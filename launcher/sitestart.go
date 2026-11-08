package main

import (
	"bytes"
	"cmp"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	goruntime "runtime"
	"slices"
	"strconv"
	"strings"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/caddyfile"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/tls"
)

// errReported is a failure whose message a child process already printed.
var errReported = errors.New("reported")

// startInputs are the start options PHP's check and prepare read, by the
// variable a reader may also set each with. A credential never reaches argv.
var startInputs = map[string]string{
	"database":       "DRUPACK_DATABASE",
	"db-host":        "DRUPACK_DB_HOST",
	"db-port":        "DRUPACK_DB_PORT",
	"db-name":        "DRUPACK_DB_NAME",
	"db-user":        "DRUPACK_DB_USER",
	"db-password":    "DRUPACK_DB_PASSWORD",
	"admin-user":     "DRUPACK_ADMIN_USER",
	"admin-password": "DRUPACK_ADMIN_PASSWORD",
	"site-name":      "DRUPACK_SITE_NAME",
}

// siteFallbackHost is the host a site's URL names when its drupack.site name does not resolve.
const siteFallbackHost = "localhost"

// packagedStart is a Packaged site's start once it has planned.
type packagedStart struct {
	l    Launch
	c    command
	site siteconfig.Site
	// e holds the environment the start's PHP calls and the server share.
	e environ
	// inputs are the typed start options PHP's check and prepare read, as
	// NAME=VALUE entries over e.
	inputs []string
	// data, files and scratch are resolved and canonical. recordedFiles is
	// files when a start named it, "" when it is Site data's own.
	data, files, recordedFiles, scratch string
	// staticRoot is "" for a site without a static listener.
	staticRoot string
	choice     tls.Choice
	plan       startPlan
	// listen and staticListen are the IP:PORT texts the refusals name.
	listen, staticListen string
}

// startSite plans a Packaged site's start, refuses what the reader's line or
// Site data gets wrong before it writes any Site data, and claims the lease.
// A held lease hands over to the server or refuses. Without --foreground, and
// with no console of its own, it hands launch.php the detach.
func startSite(l Launch, c command) error {
	site, err := siteconfig.Load(l.Application)
	if err != nil {
		return err
	}
	cwd, err := os.Getwd()
	if err != nil {
		return err
	}
	fromCwd := func(path string) string {
		if filepath.IsAbs(path) {
			return filepath.Clean(path)
		}
		return filepath.Join(cwd, path)
	}
	e := environ{entries: environment(l), fold: l.FoldCase}
	var inputs []string
	for name, variable := range startInputs {
		if value := c.options[name]; value != "" {
			inputs = append(inputs, variable+"="+value)
		}
	}
	if err := checkDatabase(c, e); err != nil {
		return err
	}
	data := fromCwd(c.options["data-dir"])
	out, err := operate(l, withInputs(e, inputs), "check", "site", runtime.Canonical(data))
	if err != nil {
		return err
	}
	var owed struct {
		Installed bool `json:"installed"`
	}
	if err := json.Unmarshal(out, &owed); err != nil {
		return err
	}
	listen := cmp.Or(c.options["listen"], "127.0.0.1:"+strconv.Itoa(site.Port))
	bind, port, _, err := siteListener(site, listen, c.options["static-listen"])
	if err != nil {
		return err
	}
	if typed := c.options["host"]; typed != "" && !validHost(typed) {
		return errors.New("Invalid --host value")
	}
	// Both paths reach the Caddyfile.
	if err := caddyfile.RefuseBraces("--data-dir", runtime.Canonical(data)); err != nil {
		return err
	}
	files := ""
	if typed := c.options["files-dir"]; typed != "" {
		files = fromCwd(typed)
		if err := caddyfile.RefuseBraces("--files-dir", runtime.Canonical(files)); err != nil {
			return err
		}
	}

	restrictUmask()
	if err := os.MkdirAll(data, 0o700); err != nil {
		return err
	}
	if data, err = filepath.EvalSymlinks(data); err != nil {
		return err
	}
	if err := siteDirectories(data, site.Static != nil); err != nil {
		return err
	}
	record, recorded, err := readListener(state.At(data), l.Name)
	if err != nil {
		return err
	}
	if files == "" && recorded {
		files = record.FilesDir
	}
	named := files != ""
	files = cmp.Or(files, filepath.Join(data, "files"))
	if err := os.MkdirAll(filepath.Join(files, "translations"), 0o700); err != nil {
		return err
	}
	if files, err = filepath.EvalSymlinks(files); err != nil {
		return err
	}
	s := packagedStart{l: l, c: c, site: site, e: e, inputs: inputs, data: runtime.Canonical(data), files: runtime.Canonical(files), listen: listen}
	if named {
		s.recordedFiles = s.files
	}
	scratch, err := filepath.EvalSymlinks(filepath.Join(data, "runtime"))
	if err != nil {
		return err
	}
	s.scratch = runtime.Canonical(scratch)
	if site.Static != nil {
		s.staticRoot = s.data + "/static"
	}
	if s.choice, err = tls.Choose(l.TLSDir, bind, cmp.Or(site.Subdomain, l.Name), siteFallbackHost, c.options["host"]); err != nil {
		return err
	}
	if s.plan, err = planStart(bind, port, c.options["static-listen"], site.Static != nil, s.choice.Host, s.choice.Scheme()); err != nil {
		return err
	}
	if s.plan.Static != nil {
		_, _, s.staticListen, _ = staticListener(c.options["static-listen"], bind, port)
	}
	putSiteEnvironment(&s.e, s.data, s.files, s.choice.Host, s.plan.URL, s.scratch, s.staticRoot)

	// The claim and both probes run before the Listener record and every install
	// step, so a served site or a taken port reaches the reader as a sentence, not
	// a bind error or a corrupted database.
	lease, err := state.At(data).Claim()
	if err != nil {
		return err
	}
	if lease == nil {
		return s.served(owed.Installed)
	}
	if portTaken(s.plan.Bind, s.plan.Port) {
		return fmt.Errorf("Another program is listening on %s. Stop it, or start on a free port: %s --listen %s:%d", listen, l.Name, s.plan.Bind, s.plan.Port+1)
	}
	if static := s.plan.Static; static != nil && portTaken(static.Bind, static.Port) {
		return fmt.Errorf("Another program is listening on %s. Stop it, or move the static listener to a free port: %s --static-listen %s:%d", s.staticListen, l.Name, urlHost(static.Bind), static.Port+1)
	}
	if c.options["foreground"] == "" && !s.consoleOwned() {
		return s.detach(lease)
	}
	return s.serve(lease)
}

// withInputs is e with inputs over it.
func withInputs(e environ, inputs []string) []string {
	with := environ{entries: slices.Clone(e.entries), fold: e.fold}
	for _, input := range inputs {
		with.put(input)
	}
	return with.entries
}

// siteListener splits a site's --listen and checks its static listener: the
// one staticListen names, or listen's address on the next port. staticPort is
// 0 for a site without one.
func siteListener(site siteconfig.Site, listen, staticListen string) (bind string, port, staticPort int, err error) {
	if bind, port, err = parseListen(listen, "--listen"); err != nil {
		return "", 0, 0, err
	}
	if site.Static != nil {
		_, staticPort, _, err = staticListener(staticListen, bind, port)
	} else if staticListen != "" {
		err = errors.New("--static-listen needs a site whose composer.json sets extra.drupack.static")
	}
	return bind, port, staticPort, err
}

// checkDatabase refuses a --database, or a DRUPACK_DATABASE, that names no driver.
func checkDatabase(c command, e environ) error {
	database, _ := e.get("DRUPACK_DATABASE")
	if !slices.Contains([]string{"sqlite", "mysql", "pgsql"}, cmp.Or(c.options["database"], database, "sqlite")) {
		return errors.New("--database requires sqlite, mysql, or pgsql")
	}
	return nil
}

// siteDirectories creates the directories a start and drush find in Site data.
func siteDirectories(data string, static bool) error {
	names := []string{"runtime", "private", "tmp", "config", "logs"}
	if static {
		names = append(names, "static")
	}
	for _, name := range names {
		if err := os.MkdirAll(filepath.Join(data, name), 0o700); err != nil {
			return err
		}
	}
	return nil
}

// readListener reads the Listener record, naming the way out of an unreadable one.
func readListener(dir state.Dir, name string) (state.Listener, bool, error) {
	record, recorded, err := dir.Listener()
	var unreadable *state.UnreadableError
	if errors.As(err, &unreadable) {
		return state.Listener{}, false, fmt.Errorf("%w. Remove that file, then start %s again to record it.", err, name)
	}
	return record, recorded, err
}

// putSiteEnvironment gives settings.php, Drush and Tome the Site data's paths,
// the host Drupal trusts and the URL its links name. staticRoot is "" for a
// site without a static listener.
func putSiteEnvironment(e *environ, data, files, host, url, scratch, staticRoot string) {
	e.put("DRUPACK_RUNTIME_DATA_DIR=" + data)
	e.put("DRUPACK_RUNTIME_FILES_DIR=" + files)
	// Drupal matches its trusted host patterns against the request's host, which keeps
	// an IPv6 address's brackets.
	e.put("DRUPACK_RUNTIME_HOST=" + urlHost(host))
	// The address a reader types, never the bind address. Without it Drupal falls back
	// to http://default, and every printed or mailed link names an unreachable host.
	e.put("DRUSH_OPTIONS_URI=" + url)
	for _, name := range []string{"TMPDIR", "TEMP", "TMP", "XDG_DATA_HOME", "XDG_CONFIG_HOME"} {
		e.put(name + "=" + scratch)
	}
	if staticRoot != "" {
		e.put("DRUPACK_RUNTIME_STATIC_ROOT=" + staticRoot)
	}
}

// portTaken reports whether anything holds bind:port. A connect answers this on
// every platform, where a trial bind would report a taken port as free under
// Windows SO_REUSEADDR.
func portTaken(bind string, port int) bool {
	conn, err := net.DialTimeout("tcp", net.JoinHostPort(probeHost(bind), strconv.Itoa(port)), time.Second)
	if err != nil {
		return false
	}
	conn.Close()
	return true
}

// loopback reports whether bind accepts this computer alone. A login link and
// the session it opens cross any other network in clear text.
func loopback(bind string) bool {
	return bind == "::1" || strings.HasPrefix(bind, "127.")
}

// consoleOwned reports whether a file manager's console started the start,
// which closing would end along with a background server.
func (s packagedStart) consoleOwned() bool {
	return s.l.ConsoleOwned || os.Getenv("DRUPACK_RUNTIME_CONSOLE_OWNED") == "1"
}

// personPresent reports whether someone watches the start: a terminal, a file
// manager's console, or the terminal a detaching parent had.
func (s packagedStart) personPresent() bool {
	return runtime.Terminal(os.Stdin) || s.consoleOwned() || os.Getenv("DRUPACK_RUNTIME_PERSON") == "1"
}

// served answers a start whose Site data another server holds. installed is
// what check read before the claim: a holder still preparing has no site to
// hand over.
func (s packagedStart) served(installed bool) error {
	if !installed {
		return fmt.Errorf("Another %s start is preparing this Site data: %s", s.l.Name, s.data)
	}
	// The record names the address that server took, not the one this start planned.
	bind, url := s.plan.Bind, siteURL(state.UnrecordedScheme, s.plan.Host, s.plan.Port)
	record, recorded, err := readListener(state.At(s.data), s.l.Name)
	if err != nil {
		return err
	}
	if recorded {
		var port int
		if bind, port, err = parseListen(record.Listen, "--listen"); err != nil {
			return err
		}
		url = siteURL(record.Scheme, record.Host, port)
	}
	if !s.personPresent() {
		return fmt.Errorf("%s already serves this Site data at %s: %s", s.l.Name, url, s.data)
	}
	// Two FrankenPHP processes over one database and one runtime directory would
	// corrupt both, so the start hands its reader the running site instead.
	fmt.Println("Creating a one-time login link.")
	link, failure, err := s.login(s.l.Application, url)
	if err != nil {
		return err
	}
	if link == "" {
		s.explainMintFailure(failure)
	}
	fmt.Printf("%s is already serving this Site data.\n\n  URL:    %s\n", s.l.Name, url)
	if link == "" {
		return nil
	}
	fmt.Printf("  Login:  %s\n", link)
	if s.c.options["no-browser"] != "" || !loopback(bind) {
		return nil
	}
	// The link is a working credential, so it reaches the opener through the
	// environment, which only this user can read.
	s.e.put("DRUPACK_RUNTIME_OPEN=" + link)
	return launch(Command{Path: s.l.Entry, Args: []string{s.l.Args[0], "browser-open"}, Env: s.e.entries, Dir: s.l.Application})
}

// login mints the one-time login link for uid 1 at url, opening the dashboard,
// through application's Drush. failure is Drush's reason when it mints none.
func (s packagedStart) login(application, url string) (link, failure string, err error) {
	e := environ{entries: slices.Clone(s.e.entries), fold: s.e.fold}
	e.put("DRUPACK_DRUSH_SCRIPT=" + runtime.Canonical(filepath.Join(application, drushScript)))
	return mint(s.l, e.entries, url, filepath.Join(application, "drush"), application, "/admin/dashboard")
}

// mint runs PHP's login operation, which prints the link or Drush's reason.
func mint(l Launch, env []string, url, drush, directory string, path ...string) (link, failure string, err error) {
	out, err := operate(l, env, append([]string{"login", url, drush, directory}, path...)...)
	if err != nil {
		return "", "", err
	}
	var minted struct {
		Link    string `json:"link"`
		Failure string `json:"failure"`
	}
	err = json.Unmarshal(out, &minted)
	return minted.Link, minted.Failure, err
}

func (s packagedStart) explainMintFailure(reason string) {
	if reason = strings.TrimRight(reason, ". "); reason != "" {
		reason = ": " + reason
	}
	fmt.Fprintf(os.Stderr, "Cannot mint a one-time login link%s. Get one with: %s drush --data-dir %s user:login /admin/dashboard\n", reason, s.l.Name, s.data)
}

// detach gives the lease up and hands launch.php the detach, which runs the
// start again in the background with --foreground and relays its log.
func (s packagedStart) detach(lease *state.Lease) error {
	if err := lease.Release(); err != nil {
		return err
	}
	// The stop command names the Site data the way the reader did, or not at all.
	written := s.c.options["data-dir"]
	if written == defaultDataDir {
		written = ""
	}
	return launch(Command{
		Path: s.l.Entry,
		Args: append([]string{s.l.Args[0], "php-cli", filepath.Join(s.l.Application, "launch.php"), "detach", s.data, written}, s.l.Args[1:]...),
		Env:  environment(s.l),
	})
}

// serve prepares the Site data under the lease, prints the addresses and execs
// the server.
func (s packagedStart) serve(lease *state.Lease) error {
	l, site, plan, choice := s.l, s.site, s.plan, s.choice
	dir := state.At(s.data)
	// Drush runs a step starts, and a child of theirs, hold the lease too, so it
	// outlives no process that writes Site data.
	if err := lease.Inherit(); err != nil {
		return err
	}
	if err := dir.ClearStopRecord(); err != nil {
		return err
	}
	listener := plan.record()
	listener.FilesDir = s.recordedFiles
	if err := dir.WriteListener(listener); err != nil {
		return err
	}
	if err := storeBundle(&s.e, state.At(s.scratch), choice); err != nil {
		return err
	}
	var hub *caddyfile.Mercure
	if site.Mercure {
		publisher, subscriber, err := dir.CreateMercureSecrets()
		if err != nil {
			return err
		}
		hub = &caddyfile.Mercure{URL: mercureURL(plan.Scheme, plan.Bind, plan.Port), Publisher: publisher, Subscriber: subscriber, DB: s.data + "/mercure.db"}
		s.e.put("DRUPACK_MERCURE_PUBLISHER_SECRET=" + publisher)
		s.e.put("DRUPACK_MERCURE_SUBSCRIBER_SECRET=" + subscriber)
		s.e.put("DRUPACK_MERCURE_URL=" + hub.URL)
	}
	application := l.Application
	if len(site.Writable) > 0 {
		if err := runtime.LaySiteApp(s.data, l.AppChecksum, appPayload, site.Writable, os.Stderr); err != nil {
			return fmt.Errorf("Cannot lay the application in Site data: %s: %w", s.data, err)
		}
		application = runtime.SiteApp(s.data)
		s.e.put("DRUPACK_RUNTIME_APP_DIR=" + runtime.Canonical(application))
	}
	application = runtime.Canonical(application)
	// Drush's, Tome's and a request's child processes run the php on PATH, which bin/
	// points back at this runtime.
	path, _ := s.e.get("PATH")
	s.e.put("PATH=" + filepath.Join(application, "bin") + string(os.PathListSeparator) + path)
	s.e.put("DRUPACK_DRUSH_SCRIPT=" + application + "/" + filepath.ToSlash(drushScript))
	prepared, err := s.prepare()
	if err != nil {
		return err
	}
	if err := layTranslations(application, filepath.Join(s.files, "translations")); err != nil {
		return err
	}
	// Every start hands its reader a one-time login link for uid 1. A start that
	// generated the password has no other way in, so it fails when the mint fails.
	// An adopted site keeps its own accounts and serves without the link.
	fmt.Println("Creating a one-time login link.")
	link, failure, err := s.login(application, plan.URL)
	if err != nil {
		return err
	}
	if link == "" {
		if prepared.Credentials && !prepared.Adopted {
			return errors.New("Cannot obtain a one-time login link for the administrator account")
		}
		s.explainMintFailure(failure)
	}
	// The addresses and paths only, never a readiness claim: the server announces that itself.
	fmt.Printf("  URL:       %s\n", plan.URL)
	if plan.Static != nil {
		fmt.Printf("  Static:    %s\n", plan.Static.URL)
	}
	if link != "" {
		fmt.Printf("  Login:     %s\n", link)
	}
	logPath := s.data + "/logs/caddy.log"
	fmt.Printf("  Site data: %s\n  Log:       %s\n", s.data, logPath)
	if link != "" && !loopback(plan.Bind) {
		fmt.Printf("No browser opens: %s accepts other computers over plain HTTP. Sign in from another computer only through a TLS proxy.\n", s.listen)
	}
	fmt.Print(nameHint(choice), trustHint(l, choice))
	// The server opens the browser once the site answers, so a start that opens none
	// clears whatever the environment it inherited held.
	s.e.drop("DRUPACK_RUNTIME_OPEN")
	if link != "" && s.c.options["no-browser"] == "" && s.personPresent() && loopback(plan.Bind) {
		s.e.put("DRUPACK_RUNTIME_OPEN=" + link)
	}
	// Every step that needed the administrator password has run. The server passes
	// its environment to every process it starts, including PHP code a site runs.
	s.e.drop("DRUPACK_ADMIN_PASSWORD")
	fmt.Println("Starting the web server.")
	id := siteToken(s.data, l.FoldCase)
	config := caddyfile.Config{
		Mode: caddyfile.Site, ID: id, Bind: plan.Bind, Port: plan.Port,
		Docroot: application + "/" + site.Docroot, TLS: choice, Mercure: hub,
		FilesDir: s.files, LogPath: logPath,
	}
	if info, err := os.Stat(application + "/drupack.caddy"); err == nil && info.Mode().IsRegular() {
		config.ProjectRules = application + "/drupack.caddy"
	}
	if plan.Static != nil {
		config.Static = &caddyfile.Static{Bind: plan.Static.Bind, Port: plan.Static.Port, Root: s.staticRoot, Dynamic: site.Static.Dynamic}
	}
	rendered, err := caddyfile.Render(config)
	if err != nil {
		return err
	}
	file, err := dir.WriteCaddyfile(rendered)
	if err != nil {
		return err
	}
	for _, value := range serverEnvironment(id, dir, choice, plan) {
		s.e.put(value)
	}
	// settings.php trusts the drupack.site name as a request host.
	s.e.put("DRUPACK_RUNTIME_ALIAS=" + choice.Alias)
	// The server names the cache entry's executable, which ps and Tome's children read.
	serverArguments := []string{l.Entry, "php-server", runtime.Canonical(file)}
	if config.ProjectRules != "" {
		// The runtime names the file when its rules fail to adapt.
		serverArguments = append(serverArguments, config.ProjectRules)
	}
	err = launch(Command{Path: l.Entry, Args: serverArguments, Env: s.e.entries, Dir: application})
	// A collected file would close its descriptor, and the lease with it, before the exec.
	goruntime.KeepAlive(lease)
	return err
}

// prepared is PHP's prepare record. Credentials reports whether an owed step
// needed the administrator's credentials, Adopted whether the database already
// held a site.
type prepared struct {
	Adopted     bool `json:"adopted"`
	Credentials bool `json:"credentials"`
}

// prepare runs PHP's prepare. Its step lines reach the reader as they run, and
// so does its refusal.
func (s packagedStart) prepare() (prepared, error) {
	command := exec.Command(s.l.Entry, "php-cli", filepath.Join(s.l.Application, "operate.php"), "prepare", s.data)
	command.Env = withInputs(s.e, s.inputs)
	var out bytes.Buffer
	command.Stdout, command.Stderr = &out, os.Stderr
	if err := command.Run(); err != nil {
		var exit *exec.ExitError
		if errors.As(err, &exit) {
			return prepared{}, errReported
		}
		return prepared{}, err
	}
	var record prepared
	return record, json.Unmarshal(out.Bytes(), &record)
}

// siteToken names one Site data in the readiness probe and the PHP cache id.
// Windows spells one path in several casings, so the hash reads a single one.
func siteToken(data string, fold bool) string {
	if fold {
		data = strings.ToLower(data)
	}
	sum := sha256.Sum256([]byte(data))
	return hex.EncodeToString(sum[:])
}

package main

import (
	"bytes"
	"cmp"
	"crypto/sha256"
	"encoding/hex"
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

// startInputs are the start options PHP's check and prepare read. Each also
// has a DRUPACK_ variable a reader may set, and a credential never reaches argv.
var startInputs = []string{"database", "db-host", "db-port", "db-name", "db-user", "db-password", "admin-user", "admin-password", "site-name"}

type packagedStart struct {
	// Facts holds what the start's PHP calls and the server share.
	Facts
	c    command
	site siteconfig.Site
	// recordedFiles is Files when a start named it, "" when it is Site data's own.
	recordedFiles string
	choice        tls.Choice
	plan          startPlan
	// listen is the IP:PORT text the refusals name.
	listen string
	// accessLog and errorLog are the files of --access-log and --error-log,
	// each "" for a start without its option.
	accessLog, errorLog string
}

// startSite plans a Packaged site's start, refuses what the reader's line or
// Site data gets wrong before it writes any Site data, and claims the lease.
// A held lease hands over to the server or refuses. Without --foreground, and
// with no console of its own, it first detaches into a child that does all of it.
func startSite(l Launch, c command) error {
	cwd, err := os.Getwd()
	if err != nil {
		return err
	}
	if c.options["foreground"] == "" && !l.ConsoleOwned {
		data, err := filepath.Abs(c.options["data-dir"])
		if err != nil {
			return err
		}
		// The stop command names the Site data the way the reader did, or not at all.
		target := ""
		if written := c.options["data-dir"]; written != defaultDataDir {
			target = " --data-dir " + shellWord(written, goruntime.GOOS == "windows")
		}
		return detachStart(l, runtime.Canonical(data)+"/logs/server.log", stopCommand(l.Args[0], cwd, target, goruntime.GOOS == "windows"))
	}
	site, err := siteconfig.Load(l.Application)
	if err != nil {
		return err
	}
	fromCwd := func(path string) string {
		if filepath.IsAbs(path) {
			return filepath.Clean(path)
		}
		return filepath.Join(cwd, path)
	}
	f := Facts{Launch: l}
	for _, name := range startInputs {
		if value := c.options[name]; value != "" {
			f.Inputs = append(f.Inputs, "DRUPACK_"+strings.ToUpper(strings.ReplaceAll(name, "-", "_"))+"="+value)
		}
	}
	if err := checkDatabase(c, f); err != nil {
		return err
	}
	data := fromCwd(c.options["data-dir"])
	var owed struct {
		Installed bool `json:"installed"`
	}
	if err := operate(l, f.Env(Operate), &owed, false, "check", "site", runtime.Canonical(data)); err != nil {
		return err
	}
	target := siteTarget(site)
	listen := cmp.Or(c.options["listen"], target.DefaultListen)
	bind, port, err := target.Listen(listen)
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
	choice, err := tls.Choose(l.TLSDir, bind, cmp.Or(site.Subdomain, l.Name), unresolvedHost, c.options["host"])
	if err != nil {
		return err
	}
	plan, err := planStart(bind, port, c.options["static-listen"], site.Static != nil, "site", choice.Host, choice.Scheme())
	if err != nil {
		return err
	}

	restrictUmask()
	if err := runtime.Create(data); err != nil {
		return err
	}
	if data, err = filepath.EvalSymlinks(data); err != nil {
		return err
	}
	warnExposed(data)
	served, err := Served(state.At(data), l.Name, target, state.Listener{FilesDir: files})
	if err != nil {
		return err
	}
	files = served.FilesDir
	named := files != ""
	files, scratch, err := siteDirs(data, files, site.Static != nil)
	if err != nil {
		return err
	}
	if named {
		warnExposed(files)
	}
	// The Caddyfile holds the resolved paths, and a link can lead to a brace.
	if err := caddyfile.RefuseBraces("--data-dir", runtime.Canonical(data)); err != nil {
		return err
	}
	if err := caddyfile.RefuseBraces("--files-dir", runtime.Canonical(files)); err != nil {
		return err
	}
	f.Data, f.Files, f.Scratch = runtime.Canonical(data), runtime.Canonical(files), runtime.Canonical(scratch)
	f.Host, f.URL = choice.Host, plan.URL
	s := packagedStart{Facts: f, c: c, site: site, choice: choice, plan: plan, listen: listen}
	if named {
		s.recordedFiles = s.Files
	}
	answered := []string{runtime.Canonical(l.Application), runtime.Canonical(runtime.SiteApp(s.Data)), s.Files}
	if site.Static != nil {
		s.StaticRoot = s.Data + "/static"
		answered = append(answered, s.StaticRoot)
	}
	if s.accessLog, s.errorLog, err = logFiles(c.options, s.Data+"/logs", answered, l.FoldCase); err != nil {
		return err
	}

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
		return fmt.Errorf("Another program is listening on %s. Stop it, or move the static listener to a free port: %s --static-listen %s:%d", s.plan.record().StaticListen, l.Name, urlHost(static.Bind), static.Port+1)
	}
	if err := takeLog(); err != nil {
		return err
	}
	return s.serve(lease)
}

// checkDatabase refuses a --database, or a DRUPACK_DATABASE, that names no driver.
func checkDatabase(c command, f Facts) error {
	if !slices.Contains([]string{"sqlite", "mysql", "pgsql"}, cmp.Or(c.options["database"], f.value("DRUPACK_DATABASE"), "sqlite")) {
		return errors.New("--database requires sqlite, mysql, or pgsql")
	}
	return nil
}

// siteDirs creates the directories a start and drush find in Site data, and the
// translations folder of files, Site data's own files directory when files is "".
// It returns the resolved files and scratch directories.
func siteDirs(data, files string, static bool) (resolved, scratch string, err error) {
	names := []string{"runtime", "private", "tmp", "config", "logs"}
	if static {
		names = append(names, "static")
	}
	for _, name := range names {
		if err := os.MkdirAll(filepath.Join(data, name), 0o700); err != nil {
			return "", "", err
		}
	}
	files = cmp.Or(files, filepath.Join(data, "files"))
	if err := os.MkdirAll(filepath.Join(files, "translations"), 0o700); err != nil {
		return "", "", err
	}
	if resolved, err = filepath.EvalSymlinks(files); err != nil {
		return "", "", err
	}
	scratch, err = filepath.EvalSymlinks(filepath.Join(data, "runtime"))
	return resolved, scratch, err
}

// warnExposed names the account other than this user, root or the system that
// can change dir, and the command that takes its access away. The command goes
// on: a folder someone else can write still serves its own site.
func warnExposed(dir string) {
	exposed, err := runtime.Exposure(dir, false)
	switch {
	case err != nil:
		fmt.Fprintf(os.Stderr, "Warning: cannot check who can change %s: %v\n", runtime.Canonical(dir), err)
	case exposed != nil:
		fmt.Fprintf(os.Stderr, "Warning: %s, so another account can change this site's code and database. %s\n", exposed, fixCommand(exposed))
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

// personPresent reports whether someone watches the start: a terminal, a file
// manager's console, or the terminal a detaching parent had.
func (s packagedStart) personPresent() bool {
	return runtime.Terminal(os.Stdin) || s.Launch.ConsoleOwned || child != nil && child.person
}

// served answers a start whose Site data another server holds. installed is
// what check read before the claim: a holder still preparing has no site to
// hand over.
func (s packagedStart) served(installed bool) error {
	if !installed {
		return fmt.Errorf("Another %s start is preparing this Site data: %s", s.Launch.Name, s.Data)
	}
	// The record names the address that server took, not the one this start
	// planned. A Site data a release before 0.6.0 installed holds no record, and
	// neither does one whose unreadable record the reader removed. Those fall
	// back to this start's plan.
	bind, url := s.plan.Bind, siteURL(state.UnrecordedScheme, s.plan.Host, s.plan.Port)
	a, err := Served(state.At(s.Data), s.Launch.Name, siteTarget(s.site), state.Listener{})
	if err != nil {
		return err
	}
	if a.Recorded {
		bind, url = a.Bind, siteURL(a.Scheme, a.Host, a.Port)
	}
	if !s.personPresent() {
		return fmt.Errorf("%s already serves this Site data at %s: %s", s.Launch.Name, url, s.Data)
	}
	// Two FrankenPHP processes over one database and one runtime directory would
	// corrupt both, so the start hands its reader the running site instead.
	fmt.Println("Creating a one-time login link.")
	link, failure, err := s.login(s.Launch.Application, url)
	if err != nil {
		return err
	}
	if link == "" {
		s.explainMintFailure(failure)
	}
	fmt.Printf("%s is already serving this Site data.\n\n  URL:    %s\n", s.Launch.Name, url)
	if link == "" {
		return nil
	}
	fmt.Printf("  Login:  %s\n", link)
	if s.c.options["no-browser"] != "" || !tls.Loopback(bind) {
		return nil
	}
	openBrowser(link)
	return nil
}

// login mints the one-time login link for uid 1 at url, opening the dashboard,
// through application's Drush. failure is Drush's reason when it mints none.
func (s packagedStart) login(application, url string) (link, failure string, err error) {
	s.Script = filepath.Join(application, runtime.DrushScript)
	return mint(s.Launch, s.Env(Drush), url, filepath.Join(application, "drush"), application, "/admin/dashboard")
}

// mint runs Drush's user:login for uid 1 at url and returns the link, or the
// reason Drush gave when it mints none. The link is a working credential bound
// for a terminal or a browser command, so it must start with url and its slash.
func mint(l Launch, env []string, url, drush, directory string, path ...string) (link, failure string, err error) {
	command := exec.Command(l.Entry, append([]string{"php-cli", drush, "user:login", "--no-browser", "--uri=" + url}, path...)...)
	command.Dir = directory
	// Drush wraps error text at COLUMNS, 80 with no terminal; a wide value keeps a
	// failed mint's reason on one line.
	command.Env = append(slices.Clone(env), "COLUMNS=1000")
	var out, reason bytes.Buffer
	command.Stdout, command.Stderr = &out, &reason
	if err := command.Run(); err != nil {
		var exit *exec.ExitError
		if !errors.As(err, &exit) {
			return "", "", errors.New("Cannot run Drush")
		}
	}
	if link = strings.TrimSpace(out.String()); !strings.HasPrefix(link, strings.TrimSuffix(url, "/")+"/") {
		return "", strings.Join(strings.Fields(reason.String()), " "), nil
	}
	return link, "", nil
}

func (s packagedStart) explainMintFailure(reason string) {
	if reason = strings.TrimRight(reason, ". "); reason != "" {
		reason = ": " + reason
	}
	fmt.Fprintf(os.Stderr, "Cannot mint a one-time login link%s. Get one with: %s drush --data-dir %s user:login /admin/dashboard\n", reason, s.Launch.Name, s.Data)
}

// serve prepares the Site data under the lease, prints the addresses and execs
// the server.
func (s packagedStart) serve(lease *state.Lease) error {
	l, site, plan, choice := s.Launch, s.site, s.plan, s.choice
	dir := state.At(s.Data)
	if err := dir.ClearStopRecord(); err != nil {
		return err
	}
	listener := plan.record()
	listener.FilesDir = s.recordedFiles
	if err := dir.WriteListener(listener); err != nil {
		return err
	}
	var err error
	if s.CAFile, err = storeBundle(s.Facts, state.At(s.Scratch), choice); err != nil {
		return err
	}
	if site.Mercure {
		if s.Hub, err = serveHub(dir, plan, s.Data+"/mercure.db"); err != nil {
			return err
		}
	}
	application := l.Application
	if len(site.Writable) > 0 {
		if err := runtime.LaySiteApp(s.Data, l.AppChecksum, appPayload, site.Writable, os.Stderr); err != nil {
			return fmt.Errorf("Cannot lay the application in Site data: %s: %w", s.Data, err)
		}
		application = runtime.SiteApp(s.Data)
		s.SiteApp = application
	}
	application = runtime.Canonical(application)
	s.Bin, s.Script = application, filepath.Join(application, runtime.DrushScript)
	prepared, err := s.prepare()
	if err != nil {
		return err
	}
	if err := layTranslations(application, filepath.Join(s.Files, "translations")); err != nil {
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
	logPath := s.Data + "/logs/caddy.log"
	fmt.Printf("  Site data: %s\n  Log:       %s\n", s.Data, logPath)
	if s.accessLog != "" {
		fmt.Printf("  Access:    %s\n", s.accessLog)
	}
	if s.errorLog != "" {
		fmt.Printf("  Errors:    %s\n", s.errorLog)
	}
	if link != "" && !tls.Loopback(plan.Bind) {
		fmt.Printf("No browser opens: %s accepts other computers over plain HTTP. Sign in from another computer only through a TLS proxy.\n", s.listen)
	}
	fmt.Print(nameHint(choice), trustHint(l, choice))
	// The server opens the browser once the site answers.
	if link != "" && s.c.options["no-browser"] == "" && s.personPresent() && tls.Loopback(plan.Bind) {
		s.Open = link
	}
	fmt.Println("Starting the web server.")
	config := caddyfile.Config{
		Mode: caddyfile.Site, ID: siteToken(s.Data, l.FoldCase),
		Docroot: application + "/" + site.Docroot, Mercure: s.Hub,
		FilesDir: s.Files, LogPath: logPath, AccessLog: s.accessLog, ErrorLog: s.errorLog,
	}
	if plan.Static != nil {
		config.Static = &caddyfile.Static{Bind: plan.Static.Bind, Port: plan.Static.Port, Root: s.StaticRoot, Dynamic: site.Static.Dynamic}
	}
	s.Alias = choice.Alias
	// The server names the cache entry's executable, which ps and Tome's children read.
	return execServer(s.Facts, dir, lease, config, choice, plan, application, l.Entry, application)
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
	var record prepared
	err := operate(s.Launch, s.Env(Operate), &record, true, "prepare", s.Data)
	return record, err
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

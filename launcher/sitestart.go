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
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/localtls"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// errReported is a failure whose message a child process already printed.
var errReported = errors.New("reported")

// startInputs are the start options PHP's check and prepare read, as the
// DRUPACK_ variables a reader may set in their place. A credential never
// reaches argv.
func startInputs(o options) []string {
	var inputs []string
	for _, input := range [][2]string{
		{"DATABASE", o.Database},
		{"DB_HOST", o.DBHost},
		{"DB_PORT", o.DBPort},
		{"DB_NAME", o.DBName},
		{"DB_USER", o.DBUser},
		{"DB_PASSWORD", o.DBPassword},
		{"ADMIN_USER", o.AdminUser},
		{"ADMIN_PASSWORD", o.AdminPassword},
		{"SITE_NAME", o.SiteName},
	} {
		if input[1] != "" {
			inputs = append(inputs, "DRUPACK_"+input[0]+"="+input[1])
		}
	}
	return inputs
}

// packagedStart is a Packaged site's start of its Site data.
type packagedStart struct {
	// Facts holds what the start's PHP calls and the server share.
	Facts
	c    command
	cwd  string
	site siteconfig.Site
	// typedData and typedFiles are --data-dir and --files-dir from the working
	// directory, before any link resolves. typedFiles is "" without the option.
	typedData, typedFiles string
	// installed is what check read before the claim.
	installed bool
	// listen is the IP:PORT text the refusals name.
	listen string
}

func (s *packagedStart) fromCwd(path string) string {
	if filepath.IsAbs(path) {
		return filepath.Clean(path)
	}
	return filepath.Join(s.cwd, path)
}

func (s *packagedStart) detach() (log, stopTarget string, err error) {
	data, err := filepath.Abs(s.c.options.DataDir)
	if err != nil {
		return "", "", err
	}
	// The stop command names the Site data the way the reader did, or not at all.
	if written := s.c.options.DataDir; written != defaultDataDir {
		stopTarget = " --data-dir " + shellWord(written, goruntime.GOOS == "windows")
	}
	return runtime.Canonical(data) + "/logs/server.log", stopTarget, nil
}

func (s *packagedStart) check() (checked, error) {
	l, o := s.Launch, s.c.options
	var err error
	if s.site, err = siteconfig.Load(l.Application); err != nil {
		return checked{}, err
	}
	if err := checkDatabase(s.c, s.Facts); err != nil {
		return checked{}, err
	}
	s.typedData = s.fromCwd(o.DataDir)
	var owed struct {
		Installed bool `json:"installed"`
	}
	if err := operate(l, s.Env(Operate), &owed, false, "check", "site", runtime.Canonical(s.typedData)); err != nil {
		return checked{}, err
	}
	s.installed = owed.Installed
	target := siteTarget(s.site)
	s.listen = cmp.Or(o.Listen, target.DefaultListen)
	bind, port, err := target.Listen(s.listen)
	if err != nil {
		return checked{}, err
	}
	if o.Host != "" && !validHost(o.Host) {
		return checked{}, errors.New("Invalid --host value")
	}
	// Both paths reach the Caddyfile.
	if err := caddyfile.RefuseBraces("--data-dir", runtime.Canonical(s.typedData)); err != nil {
		return checked{}, err
	}
	if o.FilesDir != "" {
		s.typedFiles = s.fromCwd(o.FilesDir)
		if err := caddyfile.RefuseBraces("--files-dir", runtime.Canonical(s.typedFiles)); err != nil {
			return checked{}, err
		}
	}
	return checked{
		listen: s.listen, bind: bind, port: port,
		alias: cmp.Or(s.site.Subdomain, l.Name), fallback: unresolvedHost,
		static: s.site.Static != nil, noun: "site",
	}, nil
}

// stage creates Site data and its directories, and resolves their links.
func (s *packagedStart) stage(plan startPlan) (staged, error) {
	l := s.Launch
	restrictUmask()
	if err := runtime.Create(s.typedData); err != nil {
		return staged{}, err
	}
	data, err := filepath.EvalSymlinks(s.typedData)
	if err != nil {
		return staged{}, err
	}
	warnExposed(data)
	served, err := Served(state.At(data), l.Name, siteTarget(s.site), state.Listener{FilesDir: s.typedFiles})
	if err != nil {
		return staged{}, err
	}
	named := served.FilesDir != ""
	files, scratch, err := siteDirs(data, served.FilesDir, s.site.Static != nil)
	if err != nil {
		return staged{}, err
	}
	if named {
		warnExposed(files)
	}
	// The Caddyfile holds the resolved paths, and a link can lead to a brace.
	if err := caddyfile.RefuseBraces("--data-dir", runtime.Canonical(data)); err != nil {
		return staged{}, err
	}
	if err := caddyfile.RefuseBraces("--files-dir", runtime.Canonical(files)); err != nil {
		return staged{}, err
	}
	s.Data, s.Files, s.Scratch = runtime.Canonical(data), runtime.Canonical(files), runtime.Canonical(scratch)
	s.Host, s.URL = plan.Host, plan.URL
	staged := staged{dir: state.At(data), logDir: s.Data + "/logs"}
	if named {
		staged.recordedFiles = s.Files
	}
	staged.answered = []string{runtime.Canonical(l.Application), runtime.Canonical(runtime.SiteApp(s.Data)), s.Files}
	if s.site.Static != nil {
		s.StaticRoot = s.Data + "/static"
		staged.answered = append(staged.answered, s.StaticRoot)
	}
	return staged, nil
}

// checkDatabase refuses a --database, or a DRUPACK_DATABASE, that names no driver.
func checkDatabase(c command, f Facts) error {
	if !slices.Contains([]string{"sqlite", "mysql", "pgsql"}, cmp.Or(c.options.Database, f.value("DRUPACK_DATABASE"), "sqlite")) {
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
func personPresent(l Launch) bool {
	return runtime.Terminal(os.Stdin) || l.ConsoleOwned || l.Child != nil && l.Child.person
}

// held answers a start whose Site data another server holds. A holder still
// preparing has no site to hand over.
func (s *packagedStart) held(plan startPlan) error {
	if !s.installed {
		return fmt.Errorf("Another %s start is preparing this Site data: %s", s.Launch.Name, s.Data)
	}
	// The record names the address that server took, not the one this start
	// planned. A Site data a release before 0.6.0 installed holds no record, and
	// neither does one whose unreadable record the reader removed. Those fall
	// back to this start's plan.
	bind, url := plan.Bind, siteURL(state.UnrecordedScheme, plan.Host, plan.Port)
	a, err := Served(state.At(s.Data), s.Launch.Name, siteTarget(s.site), state.Listener{})
	if err != nil {
		return err
	}
	if a.Recorded {
		bind, url = a.Bind, siteURL(a.Scheme, a.Host, a.Port)
	}
	if !personPresent(s.Launch) {
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
	if s.c.options.NoBrowser || !localtls.Loopback(bind) {
		return nil
	}
	openBrowser(link)
	return nil
}

// login mints the one-time login link for uid 1 at url, opening the dashboard,
// through application's Drush. failure is Drush's reason when it mints none.
func (s *packagedStart) login(application, url string) (link, failure string, err error) {
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
		if _, ok := errors.AsType[*exec.ExitError](err); !ok {
			return "", "", fmt.Errorf("Cannot run Drush: %w", err)
		}
	}
	if link = strings.TrimSpace(out.String()); !strings.HasPrefix(link, strings.TrimSuffix(url, "/")+"/") {
		return "", strings.Join(strings.Fields(reason.String()), " "), nil
	}
	return link, "", nil
}

func (s *packagedStart) explainMintFailure(reason string) {
	if reason = strings.TrimRight(reason, ". "); reason != "" {
		reason = ": " + reason
	}
	fmt.Fprintf(os.Stderr, "Cannot mint a one-time login link%s. Get one with: %s\n", reason, s.loginCommand())
}

// loginCommand is the command that mints a login link for this Site data.
func (s *packagedStart) loginCommand() string {
	return fmt.Sprintf("%s drush --data-dir %s user:login /admin/dashboard", s.Launch.Name, s.Data)
}

// prepare lays the application, runs PHP's prepare, whose step lines and
// refusal reach the reader as they run, and mints the login link.
func (s *packagedStart) prepare(plan startPlan, choice localtls.Choice, accessLog, errorLog string, proxies []string) (ready, error) {
	l, site := s.Launch, s.site
	dir := state.At(s.Data)
	var err error
	if s.CAFile, err = storeBundle(s.Facts, state.At(s.Scratch), choice); err != nil {
		return ready{}, err
	}
	if site.Mercure {
		if s.Hub, err = serveHub(dir, plan, s.Data+"/mercure.db"); err != nil {
			return ready{}, err
		}
	}
	application := l.Application
	if len(site.Writable) > 0 {
		if err := runtime.LaySiteApp(s.Data, l.AppChecksum, appPayload, site.Writable, os.Stderr); err != nil {
			return ready{}, fmt.Errorf("Cannot lay the application in Site data: %s: %w", s.Data, err)
		}
		application = runtime.SiteApp(s.Data)
		s.SiteApp = application
	}
	application = runtime.Canonical(application)
	s.Bin, s.Script = application, filepath.Join(application, runtime.DrushScript)
	var prepared struct {
		// Adopted reports whether the database already held a site, and
		// Credentials whether an owed step needed the administrator's.
		Adopted     bool `json:"adopted"`
		Credentials bool `json:"credentials"`
	}
	if err := operate(l, s.Env(Operate), &prepared, true, "prepare", s.Data); err != nil {
		return ready{}, err
	}
	if err := layTranslations(application, filepath.Join(s.Files, "translations")); err != nil {
		return ready{}, err
	}
	// A start hands a person a one-time login link for uid 1. A start that
	// generated the password has no other way in, so it fails when the mint fails.
	// An adopted site keeps its own accounts and serves without the link.
	// The link is a credential: a start nobody watches prints the command for
	// one, so no log holds a link.
	person, link := personPresent(l), ""
	if person {
		fmt.Println("Creating a one-time login link.")
		var failure string
		if link, failure, err = s.login(application, plan.URL); err != nil {
			return ready{}, err
		}
		if link == "" {
			if prepared.Credentials && !prepared.Adopted {
				return ready{}, errors.New("Cannot obtain a one-time login link for the administrator account")
			}
			s.explainMintFailure(failure)
		}
	}
	var summary strings.Builder
	// The addresses and paths only, never a readiness claim: the server announces that itself.
	fmt.Fprintf(&summary, "  URL:       %s\n", plan.URL)
	if plan.Static != nil {
		fmt.Fprintf(&summary, "  Static:    %s\n", plan.Static.URL)
	}
	if link != "" {
		fmt.Fprintf(&summary, "  Login:     %s\n", link)
	}
	if !person {
		fmt.Fprintf(&summary, "  Login:     run %s\n", s.loginCommand())
	}
	logPath := s.Data + "/logs/caddy.log"
	fmt.Fprintf(&summary, "  Site data: %s\n  Log:       %s\n", s.Data, logPath)
	if accessLog != "" {
		fmt.Fprintf(&summary, "  Access:    %s\n", accessLog)
	}
	if errorLog != "" {
		fmt.Fprintf(&summary, "  Errors:    %s\n", errorLog)
	}
	if link != "" && !localtls.Loopback(plan.Bind) {
		fmt.Fprintf(&summary, "No browser opens: %s accepts other computers over plain HTTP. Sign in from another computer only through a TLS proxy.\n", s.listen)
	}
	summary.WriteString(nameHint(choice) + trustHint(l, choice))
	// The server opens the browser once the site answers.
	if link != "" && !s.c.options.NoBrowser && localtls.Loopback(plan.Bind) {
		s.Open = link
	}
	summary.WriteString("Starting the web server.\n")
	config := caddyfile.Config{
		Mode: caddyfile.Site, ID: siteToken(s.Data, l.FoldCase),
		Docroot: application + "/" + site.Docroot, Mercure: s.Hub,
		FilesDir: s.Files, LogPath: logPath, AccessLog: accessLog, ErrorLog: errorLog, TrustedProxies: proxies,
	}
	if plan.Static != nil {
		config.Static = &caddyfile.Static{Bind: plan.Static.Bind, Port: plan.Static.Port, Root: s.StaticRoot, Dynamic: site.Static.Dynamic}
	}
	s.Alias = choice.Alias
	// The server names the cache entry's executable, which ps and Tome's children read.
	return ready{facts: s.Facts, config: config, summary: summary.String(), rules: application, argv0: l.Entry, cwd: application}, nil
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

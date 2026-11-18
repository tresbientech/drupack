package main

import (
	"bytes"
	"cmp"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io/fs"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"slices"
	"strconv"
	"strings"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/cache"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/caddyfile"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/fsguard"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/localtls"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/payload"
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
	// typedData is --data-dir from the working directory, before any link resolves.
	typedData string
	// installed reports whether check found an installed site before the claim.
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
		stopTarget = " --data-dir " + shellWord(written, runtime.GOOS == "windows")
	}
	return logsDir(false, state.At(data)) + "server.log", stopTarget, nil
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
	if err := operate(l, s.Env(Operate), &owed, false, "check", "site", fsguard.Canonical(s.typedData)); err != nil {
		return checked{}, err
	}
	s.installed = owed.Installed
	target := siteTarget(s.site)
	s.listen = cmp.Or(o.Listen, target.DefaultListen)
	bind, port, err := parseListen(s.listen, "--listen")
	if err != nil {
		return checked{}, err
	}
	// The path reaches the Caddyfile.
	if err := caddyfile.RefuseBraces("--data-dir", fsguard.Canonical(s.typedData)); err != nil {
		return checked{}, err
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
	if err := fsguard.Create(s.typedData); err != nil {
		return staged{}, err
	}
	data, err := filepath.EvalSymlinks(s.typedData)
	if err != nil {
		return staged{}, err
	}
	warnExposed(data)
	served, err := Served(state.At(data), l.Name, siteTarget(s.site), state.Listener{})
	if err != nil {
		return staged{}, err
	}
	files, scratch, err := siteDirs(data, served.FilesDir, s.site.Static != nil)
	if err != nil {
		return staged{}, err
	}
	// The Caddyfile holds the resolved paths, and a link can lead to a brace.
	if err := caddyfile.RefuseBraces("--data-dir", fsguard.Canonical(data)); err != nil {
		return staged{}, err
	}
	if err := caddyfile.RefuseBraces("The Files directory", fsguard.Canonical(files)); err != nil {
		return staged{}, err
	}
	s.Data, s.Files, s.Scratch = fsguard.Canonical(data), fsguard.Canonical(files), fsguard.Canonical(scratch)
	s.Host, s.URL = plan.Host, plan.URL
	staged := staged{dir: state.At(data), logDir: s.Data + "/logs"}
	staged.answered = []string{fsguard.Canonical(l.Application), fsguard.Canonical(cache.SiteApp(s.Data)), s.Files}
	if s.site.Static != nil {
		s.StaticRoot = s.Data + "/" + staticDir
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

// siteDataDirs are the folders every site keeps in Site data. staticDir joins them
// for a site with a static port.
var siteDataDirs = []string{"runtime", "private", "tmp", "config", "logs"}

const (
	staticDir = "static"
	filesName = "files"
	// folderLinks bounds a chain of Windows junctions.
	folderLinks = 64
)

// resolveFolder follows path's links. EvalSymlinks leaves a Windows junction at the end
// of a path, which Lstat reports as irregular.
func resolveFolder(path string) (string, error) {
	for range folderLinks {
		resolved, err := filepath.EvalSymlinks(path)
		if err != nil {
			return "", err
		}
		info, err := os.Lstat(resolved)
		if err != nil || info.Mode()&fs.ModeIrregular == 0 {
			return resolved, err
		}
		if path, err = os.Readlink(resolved); err != nil {
			return "", err
		}
	}
	return "", fmt.Errorf("%s leads through too many links", fsguard.Canonical(path))
}

// linkCommand is the line that makes link lead to target. mklink is a Command Prompt command.
func linkCommand(target, link string, windows bool) string {
	if windows {
		return `mklink /J "` + link + `" "` + target + `"`
	}
	return "ln -s " + shellWord(target, false) + " " + shellWord(link, false)
}

// checkRecordedFiles refuses Site data whose record names a public files folder that
// files in data does not lead to, so a site never serves an empty folder in place of its
// uploads. A recorded folder that is gone while files exists counts as moved into files.
// A recorded folder overlapping private Site data can never be linked, so its refusal
// names the way out.
func checkRecordedFiles(data, recorded string) error {
	root := filepath.Join(data, filesName)
	recorded = filepath.FromSlash(recorded)
	source, sourceErr := os.Stat(recorded)
	target, targetErr := os.Stat(root)
	for _, err := range []error{sourceErr, targetErr} {
		if err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
	}
	shownRoot, shownRecorded := fsguard.Canonical(root), fsguard.Canonical(recorded)
	if sourceErr == nil {
		resolved, err := resolveFolder(recorded)
		if err != nil {
			return err
		}
		overlaps, err := overlapsSiteData(data, resolved)
		if err != nil {
			return err
		}
		if overlaps {
			return fmt.Errorf("This Site data keeps its public files in %s, which overlaps private Site data. Move the uploads into %s, then delete %s.",
				shownRecorded, shownRoot, fsguard.Canonical(state.At(data).ListenerPath()))
		}
	}
	link := linkCommand(recorded, root, runtime.GOOS == "windows")
	switch {
	case sourceErr != nil && targetErr != nil:
		return fmt.Errorf("This Site data keeps its public files in %s, which is gone. Restore that folder, or create %s.", shownRecorded, shownRoot)
	case sourceErr != nil:
		return nil
	case targetErr != nil:
		return fmt.Errorf("This Site data keeps its public files in %s. Link them, then run this again: %s", shownRecorded, link)
	case os.SameFile(source, target):
		return nil
	}
	return fmt.Errorf("This Site data keeps its public files in %s, and %s is another folder. Move that folder aside, then run: %s", shownRecorded, shownRoot, link)
}

// overlapsSiteData reports whether the resolved folder holds Site data, or overlaps a
// folder in it that stays private. It runs once the directories exist, so another
// spelling of a reserved folder finds it.
func overlapsSiteData(data, folder string) (bool, error) {
	exposed, err := fsguard.SameOrBelow(data, folder)
	if err != nil {
		return false, err
	}
	reserved := cache.SiteAppFolders(data)
	for _, name := range append(slices.Clone(siteDataDirs), staticDir) {
		reserved = append(reserved, filepath.Join(data, name))
	}
	for _, path := range reserved {
		resolved, err := resolveFolder(path)
		if errors.Is(err, os.ErrNotExist) {
			continue
		}
		if err != nil {
			return false, err
		}
		for _, pair := range [][2]string{{folder, resolved}, {resolved, folder}} {
			below, err := fsguard.SameOrBelow(pair[0], pair[1])
			if err != nil {
				return false, err
			}
			exposed = exposed || below
		}
	}
	return exposed, nil
}

// refuseExposedFiles stops the server publishing Site data from the Files directory.
func refuseExposedFiles(data, files string) error {
	exposed, err := overlapsSiteData(data, files)
	if err != nil {
		return err
	}
	if exposed {
		return fmt.Errorf("The Files directory %s overlaps private Site data in %s. Point %s at another folder.",
			fsguard.Canonical(files), fsguard.Canonical(data), fsguard.Canonical(filepath.Join(data, filesName)))
	}
	return nil
}

// siteDirs creates the directories a start and drush find in Site data, with files and
// its translations folder. files may be a link or a mount. recorded is the folder older
// Site data names for public files, or "". data is resolved. It returns the resolved
// files and scratch directories.
func siteDirs(data, recorded string, static bool) (files, scratch string, err error) {
	names := siteDataDirs
	if static {
		names = append(slices.Clone(names), staticDir)
	}
	for _, name := range names {
		if err := os.MkdirAll(filepath.Join(data, name), 0o700); err != nil {
			return "", "", err
		}
	}
	root := filepath.Join(data, filesName)
	if recorded != "" {
		if err := checkRecordedFiles(data, recorded); err != nil {
			return "", "", err
		}
	}
	if err := os.MkdirAll(root, 0o700); err != nil {
		return "", "", err
	}
	if files, err = resolveFolder(root); err != nil {
		return "", "", err
	}
	if err := refuseExposedFiles(data, files); err != nil {
		return "", "", err
	}
	if files != root {
		warnExposed(files)
	}
	if err := os.MkdirAll(filepath.Join(files, "translations"), 0o700); err != nil {
		return "", "", err
	}
	scratch, err = filepath.EvalSymlinks(filepath.Join(data, "runtime"))
	return files, scratch, err
}

// warnExposed names the account other than this user, root or the system that
// can change dir, and the command that takes its access away. The command goes
// on: a folder someone else can write still serves its own site.
func warnExposed(dir string) {
	exposed, err := fsguard.Exposure(dir, false)
	switch {
	case err != nil:
		fmt.Fprintf(os.Stderr, "Warning: cannot check who can change %s: %v\n", fsguard.Canonical(dir), err)
	case exposed != nil:
		fmt.Fprintf(os.Stderr, "Warning: %s, so another account can change this site's code and database. %s\n", exposed, fixCommand(exposed))
	}
}

// errListening is portHeld's answer for a port a server answers on.
var errListening = errors.New("a server listens")

// portHeld returns why bind:port cannot take a listener, or nil. A connect finds a
// server, which a trial bind misses under Windows SO_REUSEADDR. A trial bind finds a
// port with no server that still refuses one: an outgoing connection's source port,
// a range Windows reserves, or an address this host does not have.
func portHeld(bind string, port int) error {
	if conn, err := net.DialTimeout("tcp", net.JoinHostPort(probeHost(bind), strconv.Itoa(port)), time.Second); err == nil {
		conn.Close()
		return errListening
	}
	listener, err := net.Listen("tcp", net.JoinHostPort(bind, strconv.Itoa(port)))
	if err != nil {
		return err
	}
	return listener.Close()
}

// personPresent reports whether someone watches the start: a terminal, a file
// manager's console, or the terminal a detaching parent had.
func personPresent(l Launch) bool {
	return payload.Terminal(os.Stdin) || l.ConsoleOwned || l.Child != nil && l.Child.person
}

// held answers a start whose Site data another server holds. A holder still
// preparing has no site to hand over.
func (s *packagedStart) held(plan startPlan) error {
	if !s.installed {
		return fmt.Errorf("Another %s start is preparing this Site data: %s", s.Launch.Name, s.Data)
	}
	// The record names the address that server took, not the one this start
	// planned. A Site data whose unreadable record the reader removed holds none,
	// and falls back to this start's plan.
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
	link, failure, err := s.login(s.Launch.Application, url)
	if err != nil {
		return err
	}
	if link == "" {
		explainMintFailure(failure)
	}
	var summary strings.Builder
	writeRows(&summary, [][2]string{{"URL:  ", url}, {"Login:", s.loginLine(link, failure)}})
	fmt.Printf("%s is already serving this Site data.\n\n%s", s.Launch.Name, summary.String())
	if page := s.c.browserPage(true, bind, link, url); page != "" {
		openBrowser(page)
	}
	return nil
}

// login mints the one-time login link for uid 1 at url, opening the dashboard,
// through application's Drush. failure is Drush's reason when it mints none.
func (s *packagedStart) login(application, url string) (link, failure string, err error) {
	s.Script = filepath.Join(application, cache.DrushScript)
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

// explainMintFailure prints the reason Drush gave for minting no login link. A
// blocked uid 1 gets no line: the start names no account state.
func explainMintFailure(reason string) {
	if adminBlocked(reason) {
		return
	}
	if reason = strings.TrimRight(reason, ". "); reason != "" {
		reason = ": " + reason
	}
	fmt.Fprintf(os.Stderr, "No login link%s.\n", reason)
}

// adminBlocked reports whether Drush refused to mint because uid 1 is blocked.
// The text is Drush's user:login refusal for a blocked account.
func adminBlocked(failure string) bool {
	return strings.Contains(failure, "is blocked and thus cannot login")
}

// nameOption is the user:login option the login command adds after a failed
// mint: a blocked uid 1 leaves the reader to name the account.
func nameOption(failure string) string {
	if adminBlocked(failure) {
		return " --name=NAME"
	}
	return ""
}

// loginLine is the Login line of a summary: link, or the command that mints
// one after a mint that failed with failure.
func (s *packagedStart) loginLine(link, failure string) string {
	return cmp.Or(link, fmt.Sprintf("run %s drush --data-dir %s user:login%s /admin/dashboard", s.Invoked, s.Data, nameOption(failure)))
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
		if err := cache.LaySiteApp(s.Data, l.AppChecksum, appPayload, site.Writable, os.Stderr); err != nil {
			return ready{}, fmt.Errorf("Cannot lay the application in Site data: %s: %w", s.Data, err)
		}
		application = cache.SiteApp(s.Data)
		s.SiteApp = application
	}
	application = fsguard.Canonical(application)
	s.Bin, s.Script = application, filepath.Join(application, cache.DrushScript)
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
	person, link, failure := personPresent(l), "", ""
	if person {
		if link, failure, err = s.login(application, plan.URL); err != nil {
			return ready{}, err
		}
		if link == "" {
			if prepared.Credentials && !prepared.Adopted {
				return ready{}, errors.New("Cannot obtain a one-time login link for the administrator account")
			}
			explainMintFailure(failure)
		}
	}
	static := ""
	if plan.Static != nil {
		static = plan.Static.URL
	}
	logs := logsDir(false, state.At(s.Data))
	var summary strings.Builder
	// The addresses and paths only, never a readiness claim: the server announces that itself.
	writeRows(&summary, [][2]string{
		{"URL:      ", plan.URL},
		{"Login:    ", s.loginLine(link, failure)},
		{"Static:   ", static},
		{"Site data:", l.shown(s.Data)},
		{"Logs:     ", l.shown(logs)},
		{"Access:   ", l.shown(accessLog)},
		{"Errors:   ", l.shown(errorLog)},
	})
	if link != "" && !localtls.Loopback(plan.Bind) {
		fmt.Fprintf(&summary, "No browser opens: %s accepts other computers over plain HTTP. Sign in from another computer only through a TLS proxy.\n", s.listen)
	}
	summary.WriteString(nameHint(choice) + trustHint(l, choice))
	// The server opens the browser once the site answers.
	s.Open = s.c.browserPage(person, plan.Bind, link, plan.URL)
	summary.WriteString("Starting the web server.\n")
	config := caddyfile.Config{
		Mode: caddyfile.Site, ID: siteToken(s.Data, l.FoldCase),
		Docroot: application + "/" + site.Docroot, Mercure: s.Hub,
		FilesDir: s.Files, LogPath: logs + "caddy.log", AccessLog: accessLog, ErrorLog: errorLog, TrustedProxies: proxies,
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

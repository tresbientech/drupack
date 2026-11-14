package main

import (
	"bytes"
	"cmp"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/cache"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/caddyfile"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/fsguard"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/localtls"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// startPlan is every listener value a start serves with. Nothing after it
// chooses a listener, a scheme or a static value again.
type startPlan struct {
	Bind   string
	Port   int
	Host   string
	Scheme string
	URL    string
	// Static is nil for a project that serves no static listener.
	Static *staticPlan
}

// record is the Listener record a start writes into its state directory.
func (p startPlan) record() state.Listener {
	record := state.Listener{Listen: urlHost(p.Bind) + ":" + strconv.Itoa(p.Port), Host: p.Host, Scheme: p.Scheme}
	if p.Static != nil {
		record.StaticListen = urlHost(p.Static.Bind) + ":" + strconv.Itoa(p.Static.Port)
	}
	return record
}

type staticPlan struct {
	Bind string
	Port int
	URL  string
}

// planStart turns the bind and port of --listen, the typed --static-listen
// ("" for none), whether the project sets a static listener, and the TLS
// answer's host and scheme into the start's listeners. noun names what the
// static refusal calls the project.
func planStart(bind string, port int, staticListen string, hasStatic bool, noun, host, scheme string) (startPlan, error) {
	plan := startPlan{
		Bind: bind, Port: port, Host: host, Scheme: scheme,
		URL: siteURL(scheme, host, port),
	}
	switch {
	case hasStatic:
		staticBind, staticPort, err := staticListener(staticListen, bind, port)
		if err != nil {
			return startPlan{}, err
		}
		plan.Static = &staticPlan{Bind: staticBind, Port: staticPort, URL: siteURL(scheme, host, staticPort)}
	case staticListen != "":
		return startPlan{}, fmt.Errorf("--static-listen needs a %s whose composer.json sets extra.drupack.static", noun)
	}
	return plan, nil
}

// drupalDocroot returns the project's docroot. The Caddyfile's guards and front
// controller are Drupal's, so a docroot without Drupal core, such as a
// WordPress site's, is refused. "." names the project itself. A Composer project
// with no vendor/, such as a fresh clone, is refused with the command that
// installs it.
func drupalDocroot(name, project, relative string) (string, error) {
	docroot := project + "/" + relative
	if relative == "." {
		docroot = project
	}
	for _, file := range []string{"index.php", "core/lib/Drupal.php"} {
		if info, err := os.Stat(docroot + "/" + file); err != nil || !info.Mode().IsRegular() {
			return "", docrootRefusal(name, project, docroot+"/"+file)
		}
	}
	return docroot, nil
}

func docrootRefusal(name, project, missing string) error {
	_, composerErr := os.Stat(siteconfig.ComposerFile(project))
	if _, vendorErr := os.Stat(filepath.Join(project, "vendor")); composerErr == nil && errors.Is(vendorErr, os.ErrNotExist) {
		return fmt.Errorf("%s has no vendor/. Run %s composer install in it first.", project, name)
	}
	return fmt.Errorf("%s is not a Drupal project: %s does not exist. %s serves Drupal alone.", project, missing, name)
}

// operate runs one operation of operate.php through the runtime's php-cli and
// decodes the record it printed into record. A refusal is the error, or with
// stream the child has printed it already and the error is errReported. A
// child that printed no refusal, or never ran, gives the run's own error. With
// stream the step lines reach the reader as they run; without, they follow the
// operation.
func operate(l Launch, env []string, record any, stream bool, arguments ...string) error {
	command := exec.Command(l.Entry, append([]string{"php-cli", filepath.Join(l.Application, "operate.php")}, arguments...)...)
	command.Env = env
	var out, stderr bytes.Buffer
	command.Stdout, command.Stderr = &out, &stderr
	if stream {
		command.Stderr = os.Stderr
	}
	if err := command.Run(); err != nil {
		var exit *exec.ExitError
		refusal := strings.TrimSpace(stderr.String())
		switch {
		case stream && errors.As(err, &exit):
			return errReported
		case refusal != "":
			return errors.New(refusal)
		}
		return fmt.Errorf("operate.php %s: %w", arguments[0], err)
	}
	os.Stderr.Write(stderr.Bytes())
	return json.Unmarshal(out.Bytes(), record)
}

// startMode holds the steps the engine's start and a Packaged site's start
// run differently. start calls each method once, in this order.
type startMode interface {
	// detach returns the server log a background start writes, and the words
	// its stop command names the target with.
	detach() (log, stopTarget string, err error)
	// check refuses what the reader's line, project or machine gets wrong. It
	// creates nothing.
	check() (checked, error)
	// stage resolves the folders the start serves and keeps its state in.
	stage(plan startPlan) (staged, error)
	// held answers a start whose lease another server holds.
	held(plan startPlan) error
	// prepare runs under the lease, once the Listener record is written, and
	// prints its progress.
	prepare(plan startPlan, choice localtls.Choice, accessLog, errorLog string, proxies []string) (ready, error)
}

type checked struct {
	// listen is --listen as the refusals name it.
	listen string
	bind   string
	port   int
	// alias is the drupack.site label, and fallback the host of a name that
	// does not resolve.
	alias, fallback string
	// static reports whether the project sets a static listener, and noun
	// names the project in the static refusal.
	static bool
	noun   string
}

type staged struct {
	dir state.Dir
	// logDir holds the default access and error logs. answered are the
	// directories a port serves.
	logDir   string
	answered []string
	// recordedFiles is the files directory a start named, "" for Site data's own.
	recordedFiles string
}

type ready struct {
	facts  Facts
	config caddyfile.Config
	// summary is the addresses and paths start prints before it becomes the server.
	summary string
	// rules, argv0 and cwd are execServer's.
	rules, argv0, cwd string
}

// start plans and runs a start. It refuses what a reader's command line,
// project or machine gets wrong before it claims the lease, and a served
// folder or a taken port before it writes the Listener record. Without
// --foreground, and with no console of its own, it first detaches into a child
// that does all of it.
func start(l Launch, c command) error {
	cwd, err := os.Getwd()
	if err != nil {
		return err
	}
	var mode startMode = &packagedStart{Launch: l, Inputs: startInputs(c.options), c: c, cwd: cwd}
	if l.Engine {
		mode = &engineStart{Launch: l, c: c}
	}
	if !c.options.Foreground && !l.ConsoleOwned {
		log, target, err := mode.detach()
		if err != nil {
			return err
		}
		return detachStart(l, log, stopCommand(l.Args[0], cwd, target, runtime.GOOS == "windows"))
	}
	checked, err := mode.check()
	if err != nil {
		return err
	}
	if host := c.options.Host; host != "" && !validHost(host) {
		return errors.New("Invalid --host value")
	}
	choice, err := localtls.Choose(l.TLSDir, checked.bind, checked.alias, checked.fallback, c.options.Host)
	if err != nil {
		return err
	}
	plan, err := planStart(checked.bind, checked.port, c.options.StaticListen, checked.static, checked.noun, choice.Host, choice.Scheme())
	if err != nil {
		return err
	}
	staged, err := mode.stage(plan)
	if err != nil {
		return err
	}
	accessLog, errorLog, err := logFiles(c.options, staged.logDir, staged.answered, l.FoldCase)
	if err != nil {
		return err
	}
	proxies, err := trustedProxies(c.options.TrustedProxies)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(staged.dir.Path(), 0o700); err != nil {
		return err
	}
	// The claim and both probes run before the Listener record and every install
	// step, so a served site or a taken port reaches the reader as a sentence, not
	// a bind error or a corrupted database.
	lease, err := staged.dir.Claim()
	if err != nil {
		return err
	}
	if lease == nil {
		return mode.held(plan)
	}
	if err := portRefusal(l.Name, checked.listen, plan, portTaken); err != nil {
		return err
	}
	if err := takeLog(l.Child); err != nil {
		return err
	}
	if err := staged.dir.ClearStopRecord(); err != nil {
		return err
	}
	// The start's --listen chooses the ports, so drush and export read the addresses back from here.
	listener := plan.record()
	listener.FilesDir = staged.recordedFiles
	if err := staged.dir.WriteListener(listener); err != nil {
		return err
	}
	ready, err := mode.prepare(plan, choice, accessLog, errorLog, proxies)
	if err != nil {
		return err
	}
	fmt.Print(ready.summary)
	return execServer(ready.facts, staged.dir, lease, ready.config, choice, plan, ready.rules, ready.argv0, ready.cwd)
}

// portRefusal names the listener of plan that another program holds, and the
// line that starts on the port after it. listen is --listen as the reader
// reads it, and taken reports whether anything holds an address.
func portRefusal(name, listen string, plan startPlan, taken func(bind string, port int) bool) error {
	if taken(plan.Bind, plan.Port) {
		return fmt.Errorf("Another program is listening on %s. Stop it, or start on a free port: %s --listen %s:%d", listen, name, plan.Bind, plan.Port+1)
	}
	if static := plan.Static; static != nil && taken(static.Bind, static.Port) {
		return fmt.Errorf("Another program is listening on %s. Stop it, or move the static listener to a free port: %s --static-listen %s:%d", plan.record().StaticListen, name, urlHost(static.Bind), static.Port+1)
	}
	return nil
}

// engineStart is the engine's start of a project folder.
type engineStart struct {
	Facts
	c                command
	project, docroot string
	composer         siteconfig.Composer
	// installed is nil when the probe cannot tell, such as for a database it cannot reach.
	installed *bool
	entry     state.Dir
	// staticRoot is "" for a project without a static listener.
	staticRoot string
}

func (e *engineStart) detach() (log, stopTarget string, err error) {
	l := e.Launch
	project, err := projectFolder(l.Name, cmp.Or(e.c.target, defaultFolder))
	if err != nil {
		return "", "", err
	}
	if e.c.target != "" {
		stopTarget = " " + shellWord(e.c.target, runtime.GOOS == "windows")
	}
	return fsguard.Canonical(state.FolderEntry(l.Root, project, l.FoldCase).Path()) + "/server.log", stopTarget, nil
}

func (e *engineStart) check() (checked, error) {
	l := e.Launch
	var err error
	if e.project, err = projectFolder(l.Name, cmp.Or(e.c.target, defaultFolder)); err != nil {
		return checked{}, err
	}
	if e.composer, err = siteconfig.ReadComposer(e.project, siteconfig.Folder); err != nil {
		return checked{}, err
	}
	// A fresh clone holds no lock, so the docroot's install hint comes before the lock's check.
	if e.docroot, err = drupalDocroot(l.Name, e.project, e.composer.Docroot); err != nil {
		return checked{}, err
	}
	if err := siteconfig.CheckStatic(e.project, e.composer); err != nil {
		return checked{}, err
	}
	// The engine has no --data-dir or --files-dir, so these are the paths of its
	// Caddyfile that a reader chooses.
	if err := caddyfile.RefuseBraces("The project folder", e.project); err != nil {
		return checked{}, err
	}
	if err := caddyfile.RefuseBraces("The docroot", e.docroot); err != nil {
		return checked{}, err
	}
	var probe struct {
		Installed *bool `json:"installed"`
	}
	if err := operate(l, e.Env(Operate), &probe, false, "check", "folder", e.project, e.docroot); err != nil {
		return checked{}, err
	}
	e.installed = probe.Installed
	listen := e.c.options.Listen
	bind, port, err := engineTarget().Listen(listen)
	if err != nil {
		return checked{}, err
	}
	return checked{
		listen: listen, bind: bind, port: port,
		alias: cmp.Or(e.composer.Subdomain, path.Base(e.project)), fallback: bind,
		static: e.composer.Static != nil, noun: "project",
	}, nil
}

func (e *engineStart) stage(plan startPlan) (staged, error) {
	l := e.Launch
	e.entry = state.FolderEntry(l.Root, e.project, l.FoldCase)
	answered := []string{e.docroot}
	if plan.Static != nil {
		var err error
		if e.staticRoot, err = engineStaticRoot(l, e.Env(Operate), e.project, e.docroot, e.entry.Path()); err != nil {
			return staged{}, err
		}
		answered = append(answered, e.staticRoot)
	}
	return staged{dir: e.entry, logDir: fsguard.Canonical(e.entry.Path()), answered: answered}, nil
}

// held hands a person the project another start serves: its address, a login
// link or the install address, and a browser on the page.
func (e *engineStart) held(plan startPlan) error {
	l := e.Launch
	if !personPresent(l) {
		return fmt.Errorf("%s already serves %s", l.Name, e.project)
	}
	// The record names the address that server took, not the one this start planned.
	bind, url := plan.Bind, plan.URL
	a, err := Served(e.entry, l.Name, engineTarget(), state.Listener{})
	if err != nil {
		return err
	}
	if a.Recorded {
		bind, url = a.Bind, siteURL(a.Scheme, a.Host, a.Port)
	}
	url = strings.TrimSuffix(url, "/")
	link, login, install, err := entrance(l, e.Env(Drush), e.project, e.installed, url)
	if err != nil {
		return err
	}
	var summary strings.Builder
	writeRows(&summary, [][2]string{{"URL:    ", url}, {"Login:  ", login}, {"Install:", install}})
	fmt.Printf("%s already serves %s.\n\n%s", l.Name, l.shown(e.project), summary.String())
	if page := e.c.browserPage(true, bind, link, install, url); page != "" {
		openBrowser(page)
	}
	return nil
}

func (e *engineStart) prepare(plan startPlan, choice localtls.Choice, accessLog, errorLog string, proxies []string) (ready, error) {
	l := e.Launch
	entryPath := fsguard.Canonical(e.entry.Path())
	var err error
	if e.CAFile, err = storeBundle(e.Facts, e.entry, choice); err != nil {
		return ready{}, err
	}
	url := strings.TrimSuffix(plan.URL, "/")
	link, login, install, err := entrance(l, e.Env(Drush), e.project, e.installed, url)
	if err != nil {
		return ready{}, err
	}
	if e.composer.Mercure {
		if e.Hub, err = serveHub(e.entry, plan, entryPath+"/mercure.db"); err != nil {
			return ready{}, err
		}
	}
	// A background server outlives its terminal, so its Caddy log goes to a file that rolls,
	// next to the server log.
	caddyLog, logs := "", ""
	if l.Child != nil {
		caddyLog, logs = entryPath+"/caddy.log", entryPath+"/"
	}
	static := ""
	if plan.Static != nil {
		static = plan.Static.URL
	}
	var summary strings.Builder
	writeRows(&summary, [][2]string{
		{"URL:    ", url},
		{"Login:  ", login},
		{"Install:", install},
		{"Static: ", static},
		{"Project:", l.shown(e.project)},
		{"Logs:   ", l.shown(logs)},
		{"Access: ", l.shown(accessLog)},
		{"Errors: ", l.shown(errorLog)},
	})
	summary.WriteString(nameHint(choice) + trustHint(l, choice) + "Starting the web server.\n")
	// The server opens the browser once the site answers.
	e.Open = e.c.browserPage(personPresent(l), plan.Bind, link, install, url)
	config := caddyfile.Config{Mode: caddyfile.Engine, ID: path.Base(entryPath), Docroot: e.docroot, Mercure: e.Hub, LogPath: caddyLog, AccessLog: accessLog, ErrorLog: errorLog, TrustedProxies: proxies}
	if plan.Static != nil {
		// The server creates no export directory.
		config.Static = &caddyfile.Static{Bind: plan.Static.Bind, Port: plan.Static.Port, Root: e.staticRoot, Dynamic: e.composer.Static.Dynamic}
	}
	e.Bin = l.Application
	return ready{facts: e.Facts, config: config, summary: summary.String(), rules: e.project, argv0: l.Args[0], cwd: e.project}, nil
}

// engineStaticRoot returns the directory Tome exports into: the project's tome_static_directory,
// or Tome's default. It refuses a directory the static port must not serve.
func engineStaticRoot(l Launch, env []string, project, docroot, cacheEntry string) (string, error) {
	var setting struct {
		// Directory is "" when settings.php sets none.
		Directory string `json:"directory"`
	}
	if err := operate(l, env, &setting, false, "static-directory", project, docroot); err != nil {
		return "", fmt.Errorf("%w\nCannot read tome_static_directory from the settings of %s", err, project)
	}
	root := staticDirectory(docroot, cmp.Or(setting.Directory, defaultStaticDirectory))
	return checkStaticRoot(root, []string{docroot, cacheEntry}, l.FoldCase)
}

// serveHub creates dir's Mercure secrets and returns the hub the server runs.
// db is the hub's database file.
func serveHub(dir state.Dir, plan startPlan, db string) (*caddyfile.Mercure, error) {
	publisher, subscriber, err := dir.CreateMercureSecrets()
	if err != nil {
		return nil, err
	}
	return &caddyfile.Mercure{URL: mercureURL(plan.Scheme, plan.Bind, plan.Port), Publisher: publisher, Subscriber: subscriber, DB: db}, nil
}

// projectFile returns rules/name when it is a regular file, else "".
func projectFile(rules, name string) string {
	if info, err := os.Stat(rules + "/" + name); err == nil && info.Mode().IsRegular() {
		return rules + "/" + name
	}
	return ""
}

// execServer renders config into dir's Caddyfile and becomes the runtime's
// server on it, under lease. rules is the folder that may hold a drupack.caddy
// and a drupack.mercure.caddy, argv0 the name the server's process shows and
// cwd the folder it runs in.
func execServer(f Facts, dir state.Dir, lease *state.Lease, config caddyfile.Config, choice localtls.Choice, plan startPlan, rules, argv0, cwd string) error {
	config.Bind, config.Port, config.TLS = plan.Bind, plan.Port, choice
	config.ProjectRules = projectFile(rules, "drupack.caddy")
	if hub := projectFile(rules, "drupack.mercure.caddy"); hub != "" {
		if config.Mercure == nil {
			return fmt.Errorf("%s holds Mercure hub directives, and %s/composer.json does not set extra.drupack.mercure to true", hub, rules)
		}
		config.Mercure.ProjectRules = hub
	}
	rendered, err := caddyfile.Render(config)
	if err != nil {
		return err
	}
	file, err := dir.WriteCaddyfile(rendered)
	if err != nil {
		return err
	}
	f.StopRecord = dir.StopRecordPath()
	err = launch(Command{Path: f.Entry, Args: serveArguments(argv0, file, config), Env: f.Env(Server), Dir: cwd})
	// A collected file would close its descriptor, and the lease with it, before the exec.
	runtime.KeepAlive(lease)
	return err
}

// serveArguments is the runtime's drupack-serve line for the Caddyfile at file.
// The runtime names each project file when the configuration fails to adapt.
func serveArguments(argv0, file string, config caddyfile.Config) []string {
	arguments := []string{argv0, "drupack-serve", fsguard.Canonical(file)}
	if config.ProjectRules != "" {
		arguments = append(arguments, config.ProjectRules)
	}
	if config.Mercure != nil && config.Mercure.ProjectRules != "" {
		arguments = append(arguments, config.Mercure.ProjectRules)
	}
	return arguments
}

// entrance returns the install address of a site not installed yet, or the
// login of an installed one: link is the one-time login link a browser opens,
// login the Login line, which holds the link or the command that mints one.
// Each may be empty. installed is nil when the probe cannot tell.
func entrance(l Launch, env []string, project string, installed *bool, url string) (link, login, install string, err error) {
	if installed != nil && !*installed {
		return "", "", url + "/core/install.php", nil
	}
	drush := project + "/" + filepath.ToSlash(cache.DrushScript)
	if info, err := os.Stat(drush); err != nil || !info.Mode().IsRegular() {
		fmt.Fprintf(os.Stderr, "No login link: %s has no Drush. composer require drush/drush adds one.\n", project)
		return "", "", "", nil
	}
	command := "run " + loginCommand(l.Name, url)
	// The link is a credential: a start nobody watches prints the command for
	// one, so no log holds a link.
	if !personPresent(l) {
		return "", command, "", nil
	}
	link, failure, err := mint(l, env, url, drush, project)
	if err != nil {
		return "", "", "", err
	}
	if link == "" {
		// The folder's own settings may block uid 1 or name no reachable database yet;
		// the server still starts, and Drupal's own error page says which.
		explainMintFailure(failure)
		return "", command, "", nil
	}
	return link, link, "", nil
}

// loginCommand is the command that mints a login link for the project served at url.
func loginCommand(name, url string) string {
	return fmt.Sprintf("%s drush user:login --uri=%s", name, url)
}

// storeBundle writes an HTTPS start's CA bundle into the folder's entry and
// returns its path. An HTTP start deletes it, so Drush stops trusting a root
// the site no longer serves, and the path is "".
func storeBundle(f Facts, entry state.Dir, choice localtls.Choice) (string, error) {
	if choice.Source == "none" {
		return "", entry.RemoveBundle()
	}
	public, err := os.ReadFile(f.value("DRUPACK_CA_FILE"))
	if err != nil {
		return "", err
	}
	root, err := os.ReadFile(choice.Root)
	if err != nil {
		return "", err
	}
	if err := entry.WriteBundle(append(append(public, '\n'), root...)); err != nil {
		return "", err
	}
	bundle, _ := entry.Bundle()
	return bundle, nil
}

func nameHint(choice localtls.Choice) string {
	if choice.Reason == "" {
		return ""
	}
	return fmt.Sprintf("Name: %s did not resolve to 127.0.0.1 (%s), so the URL uses %s.\n", choice.Alias, choice.Reason, choice.Host)
}

// trustHint stays silent for an account with no config folder, which has
// nowhere to keep a root.
func trustHint(l Launch, choice localtls.Choice) string {
	if choice.Source != "none" || !choice.Eligible || l.TLSDir == "" {
		return ""
	}
	return fmt.Sprintf("HTTPS: run \"%s trust\" once to serve this site over HTTPS.\n", l.Name)
}

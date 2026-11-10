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
	goruntime "runtime"
	"strconv"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/caddyfile"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/tls"
)

// startPlan is every listener value a start serves with. Nothing after it
// chooses a listener, a scheme or a static value again.
type startPlan struct {
	Bind   string
	Port   int
	Host   string
	Scheme string
	URL    string
	// Probe is the address the readiness check polls.
	Probe string
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
		URL:   siteURL(scheme, host, port),
		Probe: siteURL(scheme, probeHost(bind), port),
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
// WordPress site's, is refused. "." names the project itself.
func drupalDocroot(project, relative string) (string, error) {
	docroot := project + "/" + relative
	if relative == "." {
		docroot = project
	}
	for _, file := range []string{"index.php", "core/lib/Drupal.php"} {
		if info, err := os.Stat(docroot + "/" + file); err != nil || !info.Mode().IsRegular() {
			return "", fmt.Errorf("%s is not a Drupal project: %s/%s does not exist. %s serves Drupal alone.", project, docroot, file, siteName)
		}
	}
	return docroot, nil
}

// operate runs one operation of operate.php through the runtime's php-cli and
// decodes the record it printed into record. A refusal is the error, or with
// stream the child has printed it already and the error is errReported. With
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
		switch {
		case !stream:
			return errors.New(strings.TrimSpace(stderr.String()))
		case errors.As(err, &exit):
			return errReported
		}
		return err
	}
	os.Stderr.Write(stderr.Bytes())
	return json.Unmarshal(out.Bytes(), record)
}

// startEngine plans and runs the engine's start. It refuses what a reader's
// command line, project or machine gets wrong before it claims the lease.
// Without --foreground, and with no console of its own, it first detaches into
// a child that does all of it.
func startEngine(l Launch, c command) error {
	restrictUmask()
	if c.options["foreground"] == "" && !l.ConsoleOwned {
		project, err := projectFolder(cmp.Or(c.target, defaultFolder))
		if err != nil {
			return err
		}
		cwd, err := os.Getwd()
		if err != nil {
			return err
		}
		target := ""
		if c.target != "" {
			target = " " + shellWord(c.target, goruntime.GOOS == "windows")
		}
		log := runtime.Canonical(state.FolderEntry(l.Root, project, l.FoldCase).Path()) + "/server.log"
		return detachStart(l, log, stopCommand(l.Args[0], cwd, target, goruntime.GOOS == "windows"))
	}
	engine := engineTarget()
	listen, set := c.options["listen"]
	if !set {
		listen = engine.DefaultListen
	}
	bind, port, err := engine.Listen(listen)
	if err != nil {
		return err
	}
	project, err := projectFolder(cmp.Or(c.target, defaultFolder))
	if err != nil {
		return err
	}
	composer, err := siteconfig.ReadComposer(project, siteconfig.Folder)
	if err != nil {
		return err
	}
	if err := siteconfig.CheckStatic(project, composer); err != nil {
		return err
	}
	docroot, err := drupalDocroot(project, composer.Docroot)
	if err != nil {
		return err
	}
	// The engine has no --data-dir or --files-dir, so these are the paths of its
	// Caddyfile that a reader chooses.
	if err := caddyfile.RefuseBraces("The project folder", project); err != nil {
		return err
	}
	if err := caddyfile.RefuseBraces("The docroot", docroot); err != nil {
		return err
	}
	f := Facts{Launch: l}
	var probe struct {
		// Installed is nil when the probe cannot tell, such as for a database it cannot reach.
		Installed *bool `json:"installed"`
	}
	if err := operate(l, f.Env(Operate), &probe, false, "check", "folder", project, docroot); err != nil {
		return err
	}
	choice, err := tls.Choose(l.TLSDir, bind, cmp.Or(composer.Subdomain, path.Base(project)), bind, "")
	if err != nil {
		return err
	}
	plan, err := planStart(bind, port, c.options["static-listen"], composer.Static != nil, "project", choice.Host, choice.Scheme())
	if err != nil {
		return err
	}
	entry := state.FolderEntry(l.Root, project, l.FoldCase)
	staticRoot := ""
	if plan.Static != nil {
		if staticRoot, err = engineStaticRoot(l, f.Env(Operate), project, docroot, entry.Path()); err != nil {
			return err
		}
	}
	if err := os.MkdirAll(entry.Path(), 0o700); err != nil {
		return err
	}
	lease, err := entry.Claim()
	if err != nil {
		return err
	}
	if lease == nil {
		return fmt.Errorf("%s already serves %s", siteName, project)
	}
	if err := takeLog(); err != nil {
		return err
	}
	if err := entry.ClearStopRecord(); err != nil {
		return err
	}
	if f.CAFile, err = storeBundle(f, entry, choice); err != nil {
		return err
	}
	url := strings.TrimSuffix(plan.URL, "/")
	link, install, err := entrance(l, f.Env(Drush), project, probe.Installed, url)
	if err != nil {
		return err
	}
	fmt.Fprintf(os.Stdout, "  URL:     %s\n", url)
	if link != "" {
		fmt.Fprintf(os.Stdout, "  Login:   %s\n", link)
	}
	if install != "" {
		fmt.Fprintf(os.Stdout, "  Install: %s\n", install)
	}
	if plan.Static != nil {
		fmt.Fprintf(os.Stdout, "  Static:  %s\n", plan.Static.URL)
	}
	fmt.Fprintf(os.Stdout, "  Project: %s\n", project)
	fmt.Fprint(os.Stdout, nameHint(choice), trustHint(l, choice))
	fmt.Fprintln(os.Stdout, "Starting the web server.")
	entryPath := runtime.Canonical(entry.Path())
	if composer.Mercure {
		if f.Hub, err = serveHub(entry, plan, entryPath+"/mercure.db"); err != nil {
			return err
		}
	}
	// The start's --listen chooses the ports, so drush and export read the addresses back from here.
	if err := entry.WriteListener(plan.record()); err != nil {
		return err
	}
	config := caddyfile.Config{Mode: caddyfile.Engine, ID: path.Base(entryPath), Docroot: docroot, Mercure: f.Hub}
	if plan.Static != nil {
		// The server creates no export directory.
		config.Static = &caddyfile.Static{Bind: plan.Static.Bind, Port: plan.Static.Port, Root: staticRoot, Dynamic: composer.Static.Dynamic}
	}
	f.Bin = l.Application
	return execServer(f, entry, lease, config, choice, plan, project, l.Args[0], project)
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
func execServer(f Facts, dir state.Dir, lease *state.Lease, config caddyfile.Config, choice tls.Choice, plan startPlan, rules, argv0, cwd string) error {
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
	f.ID, f.Probe, f.StopRecord, f.TLSRoot = config.ID, plan.Probe, dir.StopRecordPath(), choice.Root
	serverArguments := []string{argv0, "php-server", runtime.Canonical(file)}
	// The runtime names each project file when the configuration fails to adapt.
	if config.ProjectRules != "" {
		serverArguments = append(serverArguments, config.ProjectRules)
	}
	if config.Mercure != nil && config.Mercure.ProjectRules != "" {
		serverArguments = append(serverArguments, config.Mercure.ProjectRules)
	}
	err = launch(Command{Path: f.Entry, Args: serverArguments, Env: f.Env(Server), Dir: cwd})
	// A collected file would close its descriptor, and the lease with it, before the exec.
	goruntime.KeepAlive(lease)
	return err
}

// entrance returns the install address of a site not installed yet, or the
// login link of an installed one. Either may be empty. installed is nil when
// the probe cannot tell.
func entrance(l Launch, env []string, project string, installed *bool, url string) (link, install string, err error) {
	if installed != nil && !*installed {
		return "", url + "/core/install.php", nil
	}
	drush := project + "/" + filepath.ToSlash(runtime.DrushScript)
	if info, err := os.Stat(drush); err != nil || !info.Mode().IsRegular() {
		fmt.Fprintf(os.Stderr, "No login link: %s has no Drush. composer require drush/drush adds one.\n", project)
		return "", "", nil
	}
	fmt.Fprintln(os.Stdout, "Creating a one-time login link.")
	link, failure, err := mint(l, env, url, drush, project)
	if err != nil {
		return "", "", err
	}
	if link == "" {
		// The folder's own settings may block uid 1 or name no reachable database yet;
		// the server still starts, and Drupal's own error page says which.
		fmt.Fprintf(os.Stderr, "Cannot mint a one-time login link: %s\nGet one once the site answers with: %s drush user:login --uri=%s\n", failure, siteName, url)
	}
	return link, "", nil
}

// storeBundle writes an HTTPS start's CA bundle into the folder's entry and
// returns its path. An HTTP start deletes it, so Drush stops trusting a root
// the site no longer serves, and the path is "".
func storeBundle(f Facts, entry state.Dir, choice tls.Choice) (string, error) {
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

func nameHint(choice tls.Choice) string {
	if choice.Reason == "" {
		return ""
	}
	return fmt.Sprintf("Name: %s did not resolve to 127.0.0.1 (%s), so the URL uses %s.\n", choice.Alias, choice.Reason, choice.Host)
}

// trustHint stays silent for an account with no config folder, which has
// nowhere to keep a root.
func trustHint(l Launch, choice tls.Choice) string {
	if choice.Source != "none" || !choice.Eligible || l.TLSDir == "" {
		return ""
	}
	return fmt.Sprintf("HTTPS: run \"%s trust\" once to serve this site over HTTPS.\n", siteName)
}

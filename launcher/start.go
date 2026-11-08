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
	"regexp"
	goruntime "runtime"
	"strconv"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/caddyfile"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/tls"
)

// engineListenRe is the engine's --listen: anything before a port of up to five digits.
var engineListenRe = regexp.MustCompile(`^(.+):([0-9]{1,5})$`)

// engineListen splits the engine's --listen into its bind and port.
func engineListen(listen string) (bind string, port int, err error) {
	parts := engineListenRe.FindStringSubmatch(listen)
	if parts != nil {
		port, _ = strconv.Atoi(parts[2])
	}
	if parts == nil || port > 65535 {
		return "", 0, fmt.Errorf("--listen takes IP:PORT, got %s", listen)
	}
	return strings.Trim(parts[1], "[]"), port, nil
}

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
// answer's host and scheme into the start's listeners.
func planStart(bind string, port int, staticListen string, hasStatic bool, host, scheme string) (startPlan, error) {
	plan := startPlan{
		Bind: bind, Port: port, Host: host, Scheme: scheme,
		URL:   siteURL(scheme, host, port),
		Probe: siteURL(scheme, probeHost(bind), port),
	}
	switch {
	case hasStatic:
		staticBind, staticPort, _, err := staticListener(staticListen, bind, port)
		if err != nil {
			return startPlan{}, err
		}
		plan.Static = &staticPlan{Bind: staticBind, Port: staticPort, URL: siteURL(scheme, host, staticPort)}
	case staticListen != "":
		return startPlan{}, errors.New("--static-listen needs a project whose composer.json sets extra.drupack.static")
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

// refuseBraces refuses a project or docroot holding { or }, which Caddy reads as a
// placeholder. The engine has no --data-dir or --files-dir, so these are the paths
// of its Caddyfile that a reader chooses.
func refuseBraces(project, docroot string) error {
	if err := caddyfile.RefuseBraces("The project folder", project); err != nil {
		return err
	}
	return caddyfile.RefuseBraces("The docroot", docroot)
}

// operate runs one operation of the engine's operate.php through the runtime's
// php-cli and returns the record it printed. Its failure message is the error.
func operate(l Launch, env []string, arguments ...string) ([]byte, error) {
	command := exec.Command(l.Entry, append([]string{"php-cli", filepath.Join(l.Application, "operate.php")}, arguments...)...)
	command.Env = env
	var out, refusal bytes.Buffer
	command.Stdout, command.Stderr = &out, &refusal
	if err := command.Run(); err != nil {
		return nil, errors.New(strings.TrimSpace(refusal.String()))
	}
	os.Stderr.Write(refusal.Bytes())
	return out.Bytes(), nil
}

// startEngine plans and runs the engine's start. It refuses what a reader's
// command line, project or machine gets wrong before it detaches or claims the
// lease. Without --foreground, and with no console of its own, it hands
// serve.php the detach.
func startEngine(l Launch, c command) error {
	listen, set := c.options["listen"]
	if !set {
		listen = defaultListen
	}
	bind, port, err := engineListen(listen)
	if err != nil {
		return err
	}
	project, err := projectFolder(cmp.Or(c.target, "."))
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
	if err := refuseBraces(project, docroot); err != nil {
		return err
	}
	e := environ{entries: environment(l), fold: l.FoldCase}
	foreground := c.options["foreground"] != "" || l.ConsoleOwned
	// The detaching start asks for the platform check alone, since the background start probes the site.
	checkArguments := []string{"check", project}
	if foreground {
		checkArguments = append(checkArguments, docroot)
	}
	out, err := operate(l, e.entries, checkArguments...)
	if err != nil {
		return err
	}
	var probe struct {
		// Installed is nil when the probe cannot tell, such as for a database it cannot reach.
		Installed *bool `json:"installed"`
	}
	if err := json.Unmarshal(out, &probe); err != nil {
		return err
	}
	choice, err := tls.Choose(l.TLSDir, bind, cmp.Or(composer.Subdomain, path.Base(project)), bind, "")
	if err != nil {
		return err
	}
	plan, err := planStart(bind, port, c.options["static-listen"], composer.Static != nil, choice.Host, choice.Scheme())
	if err != nil {
		return err
	}
	if !foreground {
		return launch(Command{
			Path: l.Entry,
			Args: append([]string{l.Args[0], "php-cli", filepath.Join(l.Application, "serve.php"), "detach", c.target}, c.words...),
			Env:  e.entries,
		})
	}
	return engineStart{l, e, project, docroot, composer, choice, plan, probe.Installed}.serve()
}

// engineStart is a foreground start once it has planned.
type engineStart struct {
	l        Launch
	e        environ
	project  string
	docroot  string
	composer siteconfig.Composer
	choice   tls.Choice
	plan     startPlan
	// installed is nil when the probe cannot tell.
	installed *bool
}

// serve claims the folder's lease, prints the addresses and execs the server.
// e holds the environment the server and the start's children share.
func (s engineStart) serve() error {
	l, e, project, docroot, composer, choice, plan := s.l, s.e, s.project, s.docroot, s.composer, s.choice, s.plan
	entry := state.FolderEntry(l.Root, project, l.FoldCase)
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
	if err := entry.ClearStopRecord(); err != nil {
		return err
	}
	if err := storeBundle(&e, entry, choice); err != nil {
		return err
	}
	url := strings.TrimSuffix(plan.URL, "/")
	link, install, err := s.entrance(url)
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
	// The server opens no browser, so a value inherited from an earlier start is cleared.
	e.drop("DRUPACK_RUNTIME_OPEN")
	entryPath := runtime.Canonical(entry.Path())
	var hub *caddyfile.Mercure
	if composer.Mercure {
		publisher, subscriber, err := entry.CreateMercureSecrets()
		if err != nil {
			return err
		}
		hub = &caddyfile.Mercure{
			URL: mercureURL(plan.Scheme, plan.Bind, plan.Port), Publisher: publisher, Subscriber: subscriber, DB: entryPath + "/mercure.db",
		}
		e.put("DRUPACK_MERCURE_PUBLISHER_SECRET=" + publisher)
		e.put("DRUPACK_MERCURE_SUBSCRIBER_SECRET=" + subscriber)
		e.put("DRUPACK_MERCURE_URL=" + hub.URL)
	}
	// The start's --listen chooses the ports, so drush and export read the addresses back from here.
	if err := entry.WriteListener(plan.record()); err != nil {
		return err
	}
	config := caddyfile.Config{
		Mode: caddyfile.Engine, ID: path.Base(entryPath), Bind: plan.Bind, Port: plan.Port,
		Docroot: docroot, TLS: choice, Mercure: hub,
	}
	if info, err := os.Stat(project + "/drupack.caddy"); err == nil && info.Mode().IsRegular() {
		config.ProjectRules = project + "/drupack.caddy"
	}
	if plan.Static != nil {
		// Tome's default directory, html beside the docroot. The server creates none.
		config.Static = &caddyfile.Static{Bind: plan.Static.Bind, Port: plan.Static.Port, Root: path.Dir(docroot) + "/html", Dynamic: composer.Static.Dynamic}
	}
	rendered, err := caddyfile.Render(config)
	if err != nil {
		return err
	}
	file, err := entry.WriteCaddyfile(rendered)
	if err != nil {
		return err
	}
	for _, value := range serverEnvironment(entryPath, choice, plan) {
		e.put(value)
	}
	// Drush's, Tome's and a request's child processes run the php on PATH, which bin/ points
	// back at this runtime.
	path, _ := e.get("PATH")
	e.put("PATH=" + filepath.Join(l.Application, "bin") + string(os.PathListSeparator) + path)
	if err := lease.Inherit(); err != nil {
		return err
	}
	serverArguments := []string{l.Args[0], "php-server", file}
	if config.ProjectRules != "" {
		// The runtime names the file when its rules fail to adapt.
		serverArguments = append(serverArguments, config.ProjectRules)
	}
	err = launch(Command{
		Path: l.Entry,
		Args: serverArguments,
		Env:  e.entries,
		Dir:  project,
	})
	// A collected file would close its descriptor, and the lease with it, before the exec.
	goruntime.KeepAlive(lease)
	return err
}

// entrance returns the install address of a site not installed yet, or the
// login link of an installed one. Either may be empty.
func (s engineStart) entrance(url string) (link, install string, err error) {
	if s.installed != nil && !*s.installed {
		return "", url + "/core/install.php", nil
	}
	project := s.project
	drush := project + "/" + filepath.ToSlash(drushScript)
	if info, err := os.Stat(drush); err != nil || !info.Mode().IsRegular() {
		fmt.Fprintf(os.Stderr, "No login link: %s has no Drush. composer require drush/drush adds one.\n", project)
		return "", "", nil
	}
	fmt.Fprintln(os.Stdout, "Creating a one-time login link.")
	out, err := operate(s.l, s.e.entries, "login", url, drush, project)
	if err != nil {
		return "", "", err
	}
	var minted struct {
		Link    string `json:"link"`
		Failure string `json:"failure"`
	}
	if err := json.Unmarshal(out, &minted); err != nil {
		return "", "", err
	}
	if minted.Link == "" {
		// The folder's own settings may block uid 1 or name no reachable database yet;
		// the server still starts, and Drupal's own error page says which.
		fmt.Fprintf(os.Stderr, "Cannot mint a one-time login link: %s\nGet one once the site answers with: %s drush user:login --uri=%s\n", minted.Failure, siteName, url)
	}
	return minted.Link, "", nil
}

// storeBundle writes an HTTPS start's CA bundle into the folder's entry and
// points e's children at it. An HTTP start deletes it, so Drush stops trusting
// a root the site no longer serves.
func storeBundle(e *environ, entry state.Dir, choice tls.Choice) error {
	if choice.Source == "none" {
		return entry.RemoveBundle()
	}
	publicRoots, _ := e.get("DRUPACK_CA_FILE")
	public, err := os.ReadFile(publicRoots)
	if err != nil {
		return err
	}
	root, err := os.ReadFile(choice.Root)
	if err != nil {
		return err
	}
	if err := entry.WriteBundle(append(append(public, '\n'), root...)); err != nil {
		return err
	}
	bundle, _ := entry.Bundle()
	e.put("DRUPACK_CA_FILE=" + runtime.Canonical(bundle))
	return nil
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

// serverEnvironment lists the variables the runtime's watch reads: the readiness
// address and token, the stop record and the root a client verifies HTTPS with.
func serverEnvironment(entry string, choice tls.Choice, plan startPlan) []string {
	entries := []string{
		"DRUPACK_RUNTIME_ID=" + path.Base(entry),
		"DRUPACK_RUNTIME_URL=" + plan.Probe,
		"DRUPACK_RUNTIME_STOP_RECORD=" + entry + "/stop.json",
	}
	if choice.Root != "" {
		entries = append(entries, "DRUPACK_RUNTIME_TLS_ROOT="+choice.Root)
	}
	return entries
}

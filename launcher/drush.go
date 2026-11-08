package main

import (
	"cmp"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strconv"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// unrecordedHost is the host drush addresses on Site data no start has served.
const unrecordedHost = "localhost"

// drushScript is where Drush lives under an application or a project.
var drushScript = filepath.Join("vendor", "drush", "drush", "drush.php")

// drushPlan returns the runtime call that runs c's Drush words. cwd is the
// reader's directory. A site's call reads the Site data c names, the engine's
// the project holding cwd, and both give Drush and settings.php the environment
// the last start served. Neither starts a server or takes a lease.
func drushPlan(l Launch, c command, cwd string) (Command, error) {
	e := environ{entries: environment(l), fold: l.FoldCase}
	if l.Engine {
		return engineDrush(l, c, cwd, e)
	}
	return siteDrush(l, c, cwd, e)
}

// drushCall runs application's drush entry on script with words. Drush's
// children, and Tome's, run the php on PATH, which bin/ points back at the runtime.
func drushCall(l Launch, e environ, application, script string, words []string) Command {
	e.put("DRUPACK_DRUSH_SCRIPT=" + runtime.Canonical(script))
	return runtimePHPCall(l, e, application, append([]string{filepath.Join(application, "drush")}, words...))
}

// runtimePHPCall runs arguments through the runtime's php-cli with application's
// bin/ first on PATH, so a child that runs php reaches the runtime again.
func runtimePHPCall(l Launch, e environ, application string, arguments []string) Command {
	path, _ := e.get("PATH")
	e.put("PATH=" + filepath.Join(application, "bin") + string(os.PathListSeparator) + path)
	return Command{
		Path: l.Entry,
		Args: append([]string{l.Args[0], "php-cli"}, arguments...),
		Env:  slices.Clone(e.entries),
	}
}

func siteDrush(l Launch, c command, cwd string, e environ) (Command, error) {
	site, err := siteconfig.Load(l.Application)
	if err != nil {
		return Command{}, err
	}
	// Drush's own help and list read no site.
	if len(c.words) > 0 && slices.Contains([]string{"--help", "-h", "list"}, c.words[0]) {
		return drushCall(l, e, l.Application, filepath.Join(l.Application, drushScript), c.words), nil
	}
	fromCwd := func(path string) string {
		if filepath.IsAbs(path) {
			return path
		}
		return filepath.Join(cwd, path)
	}
	data := fromCwd(c.options["data-dir"])
	if _, err := os.Stat(filepath.Join(data, "settings.php")); err != nil {
		return Command{}, fmt.Errorf("This Site data has no site yet: %s. Start %s once to create one.", runtime.Canonical(data), l.Name)
	}
	record, recorded, err := readListener(state.At(data), l.Name)
	if err != nil {
		return Command{}, err
	}
	listen, staticListen, host, scheme := c.options["listen"], c.options["static-listen"], c.options["host"], "http"
	files := ""
	if c.options["files-dir"] != "" {
		files = fromCwd(c.options["files-dir"])
	}
	if recorded {
		listen = cmp.Or(listen, record.Listen)
		staticListen = cmp.Or(staticListen, record.StaticListen)
		host = cmp.Or(host, record.Host)
		scheme = record.Scheme
		files = cmp.Or(files, record.FilesDir)
	}
	listen = cmp.Or(listen, "127.0.0.1:"+strconv.Itoa(site.Port))
	bind, port, err := siteListener(site, listen, staticListen)
	if err != nil {
		return Command{}, err
	}
	// A reader types --host. A recorded one is the launcher's own.
	if typed := c.options["host"]; typed != "" && !validHost(typed) {
		return Command{}, errors.New("Invalid --host value")
	}
	host = cmp.Or(host, unrecordedHost)

	restrictUmask()
	data, err = filepath.EvalSymlinks(data)
	if err != nil {
		return Command{}, err
	}
	if err := siteDirectories(data, site.Static != nil); err != nil {
		return Command{}, err
	}
	files = cmp.Or(files, filepath.Join(data, "files"))
	if err := os.MkdirAll(filepath.Join(files, "translations"), 0o700); err != nil {
		return Command{}, err
	}
	if files, err = filepath.EvalSymlinks(files); err != nil {
		return Command{}, err
	}
	scratch, err := filepath.EvalSymlinks(filepath.Join(data, "runtime"))
	if err != nil {
		return Command{}, err
	}

	staticRoot := ""
	if site.Static != nil {
		staticRoot = runtime.Canonical(filepath.Join(data, "static"))
	}
	putSiteEnvironment(&e, runtime.Canonical(data), runtime.Canonical(files), host, siteURL(scheme, host, port), runtime.Canonical(scratch), staticRoot)
	if bundle, ok := state.At(scratch).Bundle(); ok {
		e.put("DRUPACK_CA_FILE=" + runtime.Canonical(bundle))
	}
	if site.Mercure {
		if err := putMercure(&e, state.At(data), mercureURL(scheme, bind, port)); err != nil {
			return Command{}, err
		}
	}
	application := l.Application
	if len(site.Writable) > 0 {
		if !runtime.SiteAppCurrent(data, l.AppChecksum) {
			return Command{}, fmt.Errorf("This Site data holds another release's application. Start %s once to lay this release's: %s", l.Name, runtime.Canonical(data))
		}
		application = runtime.SiteApp(data)
		e.put("DRUPACK_RUNTIME_APP_DIR=" + runtime.Canonical(application))
	}
	if err := layTranslations(application, filepath.Join(files, "translations")); err != nil {
		return Command{}, err
	}
	return drushCall(l, e, application, filepath.Join(application, drushScript), c.words), nil
}

// layTranslations copies the application's bundled translations into directory,
// keeping any file already there.
func layTranslations(application, directory string) error {
	bundled, err := filepath.Glob(filepath.Join(application, "translations", "*.po"))
	if err != nil {
		return err
	}
	for _, source := range bundled {
		destination := filepath.Join(directory, filepath.Base(source))
		if _, err := os.Stat(destination); err == nil {
			continue
		}
		content, err := os.ReadFile(source)
		if err != nil {
			return err
		}
		if err := os.WriteFile(destination, content, 0o666); err != nil {
			return fmt.Errorf("Cannot install translation resource: %s", runtime.Canonical(destination))
		}
	}
	return nil
}

// putMercure hands Drush the hub's secrets and address. A state no start has
// served holds no secrets, and drush creates none.
func putMercure(e *environ, dir state.Dir, url string) error {
	publisher, subscriber, ok, err := dir.MercureSecrets()
	if err != nil || !ok {
		return err
	}
	e.put("DRUPACK_MERCURE_PUBLISHER_SECRET=" + publisher)
	e.put("DRUPACK_MERCURE_SUBSCRIBER_SECRET=" + subscriber)
	e.put("DRUPACK_MERCURE_URL=" + url)
	return nil
}

// engineProject is the engine project a call addresses and the cache entry holding
// what its last start served.
type engineProject struct {
	project  string
	composer siteconfig.Composer
	entry    state.Dir
}

// openFolder finds the project holding cwd, the nearest directory at or above it
// with a vendor/autoload.php, and reads its composer.json.
func openFolder(l Launch, cwd string) (engineProject, error) {
	start, err := filepath.EvalSymlinks(cwd)
	if err != nil {
		return engineProject{}, err
	}
	project, err := vendorRoot(start)
	if err != nil {
		return engineProject{}, err
	}
	composer, err := siteconfig.ReadComposer(project, siteconfig.Folder)
	if err != nil {
		return engineProject{}, err
	}
	if err := siteconfig.CheckStatic(project, composer); err != nil {
		return engineProject{}, err
	}
	return engineProject{project, composer, state.FolderEntry(l.Root, runtime.Canonical(project), l.FoldCase)}, nil
}

// drush returns the call that runs words in the project's Drush. Drush and
// settings.php get the CA bundle and hub of the last start.
func (f engineProject) drush(l Launch, e environ, words []string) (Command, error) {
	if bundle, ok := f.entry.Bundle(); ok {
		e.put("DRUPACK_CA_FILE=" + runtime.Canonical(bundle))
	}
	if f.composer.Mercure {
		record, recorded, err := f.entry.Listener()
		if err != nil {
			return Command{}, err
		}
		if recorded {
			bind, port, err := parseListen(record.Listen, "--listen")
			if err != nil {
				return Command{}, err
			}
			if err := putMercure(&e, f.entry, mercureURL(record.Scheme, bind, port)); err != nil {
				return Command{}, err
			}
		}
	}
	script := filepath.Join(f.project, drushScript)
	if info, err := os.Stat(script); err != nil || !info.Mode().IsRegular() {
		return Command{}, fmt.Errorf("%s has no Drush: %s does not exist. Run composer require drush/drush in it.", runtime.Canonical(f.project), runtime.Canonical(script))
	}
	return drushCall(l, e, l.Application, script, words), nil
}

func engineDrush(l Launch, c command, cwd string, e environ) (Command, error) {
	f, err := openFolder(l, cwd)
	if err != nil {
		return Command{}, err
	}
	return f.drush(l, e, c.words)
}

// vendorRoot finds the project holding start: the nearest directory at or above
// it with a vendor/autoload.php. Drupal core and many modules carry a
// composer.json and no vendor/.
func vendorRoot(start string) (string, error) {
	for dir := start; ; dir = filepath.Dir(dir) {
		if info, err := os.Stat(filepath.Join(dir, "vendor", "autoload.php")); err == nil && info.Mode().IsRegular() {
			return dir, nil
		}
		if filepath.Dir(dir) == dir {
			return "", fmt.Errorf("No vendor/autoload.php in %s or any directory above it. Run composer install in the project.", runtime.Canonical(start))
		}
	}
}

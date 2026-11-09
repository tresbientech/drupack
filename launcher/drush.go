package main

import (
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"slices"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

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
		return drushCall(l, e, l.Application, filepath.Join(l.Application, runtime.DrushScript), c.words), nil
	}
	s, err := openSite(l, site, c, cwd, e)
	if err != nil {
		return Command{}, err
	}
	return s.drush(l, c.words), nil
}

// openedSite is a Site data a drush or export call addresses: the application it
// runs, the environment the last start served and the static listener's port.
type openedSite struct {
	application string
	e           environ
	// staticURL is where Tome exports, "" for a site without a static listener.
	staticURL string
}

func (s openedSite) drush(l Launch, words []string) Command {
	return drushCall(l, s.e, s.application, filepath.Join(s.application, runtime.DrushScript), words)
}

// openSite reads the Site data c names and gives Drush and settings.php the
// environment the last start served. It starts no server and takes no lease.
func openSite(l Launch, site siteconfig.Site, c command, cwd string, e environ) (openedSite, error) {
	fromCwd := func(path string) string {
		if filepath.IsAbs(path) {
			return path
		}
		return filepath.Join(cwd, path)
	}
	data := fromCwd(c.options["data-dir"])
	if _, err := os.Stat(filepath.Join(data, "settings.php")); err != nil {
		return openedSite{}, fmt.Errorf("This Site data has no site yet: %s. Start %s once to create one.", runtime.Canonical(data), l.Name)
	}
	typed := state.Listener{Listen: c.options["listen"], StaticListen: c.options["static-listen"], Host: c.options["host"]}
	if c.options["files-dir"] != "" {
		typed.FilesDir = fromCwd(c.options["files-dir"])
	}
	served, err := Served(state.At(data), l.Name, siteTarget(site), typed)
	if err != nil {
		return openedSite{}, err
	}
	plan, err := served.Plan(site.Static != nil, "site")
	if err != nil {
		return openedSite{}, err
	}

	restrictUmask()
	data, err = filepath.EvalSymlinks(data)
	if err != nil {
		return openedSite{}, err
	}
	warnExposed(data)
	named := served.FilesDir != ""
	files, scratch, err := siteDirs(data, served.FilesDir, site.Static != nil)
	if err != nil {
		return openedSite{}, err
	}
	if named {
		warnExposed(files)
	}
	staticRoot, staticURL := "", ""
	if plan.Static != nil {
		staticRoot = runtime.Canonical(filepath.Join(data, "static"))
		staticURL = plan.Static.URL
	}
	putSiteEnvironment(&e, runtime.Canonical(data), runtime.Canonical(files), plan.Host, plan.URL, runtime.Canonical(scratch), staticRoot)
	if bundle, ok := state.At(scratch).Bundle(); ok {
		e.put("DRUPACK_CA_FILE=" + runtime.Canonical(bundle))
	}
	if site.Mercure {
		if err := putMercure(&e, state.At(data), served.Mercure()); err != nil {
			return openedSite{}, err
		}
	}
	application := l.Application
	if len(site.Writable) > 0 {
		if !runtime.SiteAppCurrent(data, l.AppChecksum) {
			return openedSite{}, fmt.Errorf("This Site data holds another release's application. Start %s once to lay this release's: %s", l.Name, runtime.Canonical(data))
		}
		application = runtime.SiteApp(data)
		e.put("DRUPACK_RUNTIME_APP_DIR=" + runtime.Canonical(application))
	}
	if err := layTranslations(application, filepath.Join(files, "translations")); err != nil {
		return openedSite{}, err
	}
	return openedSite{application, e, staticURL}, nil
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
	putMercureEnvironment(e, publisher, subscriber, url)
	return nil
}

// putMercureEnvironment gives a process the hub's secrets and address.
func putMercureEnvironment(e *environ, publisher, subscriber, url string) {
	e.put("DRUPACK_MERCURE_PUBLISHER_SECRET=" + publisher)
	e.put("DRUPACK_MERCURE_SUBSCRIBER_SECRET=" + subscriber)
	e.put("DRUPACK_MERCURE_URL=" + url)
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
	project, err := vendorRoot(cwd)
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
		a, err := Served(f.entry, l.Name, engineTarget(), state.Listener{})
		if err != nil {
			return Command{}, err
		}
		if a.Recorded {
			if err := putMercure(&e, f.entry, a.Mercure()); err != nil {
				return Command{}, err
			}
		}
	}
	script := filepath.Join(f.project, runtime.DrushScript)
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

// vendorRoot finds the project holding cwd: the nearest directory at or above
// it with a vendor/autoload.php. Drupal core and many modules carry a
// composer.json and no vendor/. The autoloader runs as this user, so the first
// one found must belong, with its folders, to this user or the system. A link at
// vendor/ or the autoloader is refused: whoever owns the link can retarget it
// after the owner check.
func vendorRoot(cwd string) (string, error) {
	start, err := filepath.EvalSymlinks(cwd)
	if err != nil {
		return "", err
	}
	for dir := start; ; dir = filepath.Dir(dir) {
		autoload := filepath.Join(dir, "vendor", "autoload.php")
		if info, err := os.Stat(autoload); err == nil && info.Mode().IsRegular() {
			for _, path := range []string{autoload, filepath.Dir(autoload)} {
				info, err := os.Lstat(path)
				if err != nil {
					return "", err
				}
				if info.Mode()&(fs.ModeSymlink|fs.ModeIrregular) != 0 {
					return "", fmt.Errorf("Refusing the project at %s: %s is a link. Run drush from a project whose vendor/ is its own.", runtime.Canonical(dir), runtime.Canonical(path))
				}
			}
			for _, path := range []string{autoload, filepath.Dir(autoload), dir} {
				account, trusted, err := runtime.Owner(path)
				if err != nil {
					return "", err
				}
				if !trusted {
					return "", fmt.Errorf("Refusing the project at %s: %s is owned by %s. Run drush from a project you own.", runtime.Canonical(dir), runtime.Canonical(path), account)
				}
			}
			return dir, nil
		}
		if filepath.Dir(dir) == dir {
			return "", fmt.Errorf("No vendor/autoload.php in %s or any directory above it. Run composer install in the project.", runtime.Canonical(start))
		}
	}
}

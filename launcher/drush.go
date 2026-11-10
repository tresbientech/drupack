package main

import (
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"slices"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/caddyfile"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// drushCall runs application's drush entry on script with words. Drush's
// children, and Tome's, run the php on PATH, which bin/ points back at the runtime.
func drushCall(f Facts, k Kind, application, script string, words []string) Command {
	f.Script = script
	return runtimePHPCall(f, k, application, append([]string{filepath.Join(application, "drush")}, words...))
}

// runtimePHPCall runs arguments through the runtime's php-cli with application's
// bin/ first on PATH, so a child that runs php reaches the runtime again.
func runtimePHPCall(f Facts, k Kind, application string, arguments []string) Command {
	f.Bin = application
	return Command{Path: f.Entry, Args: append([]string{f.Args[0], "php-cli"}, arguments...), Env: f.Env(k)}
}

func siteDrush(f Facts, c command, cwd string) (Command, error) {
	site, err := siteconfig.Load(f.Application)
	if err != nil {
		return Command{}, err
	}
	// Drush's own help and list read no site.
	if len(c.words) > 0 && slices.Contains([]string{"--help", "-h", "list"}, c.words[0]) {
		return drushCall(f, Drush, f.Application, filepath.Join(f.Application, runtime.DrushScript), c.words), nil
	}
	s, err := openSite(f, site, c, cwd)
	if err != nil {
		return Command{}, err
	}
	return s.drush(Drush, c.words), nil
}

// openedSite is a Site data a drush or export call addresses: the application it
// runs, what the last start served and the static listener's port.
type openedSite struct {
	application string
	f           Facts
	// staticURL is where Tome exports, "" for a site without a static listener.
	staticURL string
}

func (s openedSite) drush(k Kind, words []string) Command {
	return drushCall(s.f, k, s.application, filepath.Join(s.application, runtime.DrushScript), words)
}

// openSite reads the Site data c names and gives Drush and settings.php the
// environment the last start served. It starts no server and takes no lease.
func openSite(f Facts, site siteconfig.Site, c command, cwd string) (openedSite, error) {
	l := f.Launch
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
	f.Data, f.Files, f.Scratch = runtime.Canonical(data), runtime.Canonical(files), runtime.Canonical(scratch)
	f.Host, f.URL = plan.Host, plan.URL
	staticURL := ""
	if plan.Static != nil {
		f.StaticRoot = runtime.Canonical(filepath.Join(data, "static"))
		staticURL = plan.Static.URL
	}
	if bundle, ok := state.At(scratch).Bundle(); ok {
		f.CAFile = bundle
	}
	if site.Mercure {
		if f.Hub, err = servedHub(state.At(data), served.Mercure()); err != nil {
			return openedSite{}, err
		}
	}
	application := l.Application
	if len(site.Writable) > 0 {
		if !runtime.SiteAppCurrent(data, l.AppChecksum) {
			return openedSite{}, fmt.Errorf("This Site data holds another release's application. Start %s once to lay this release's: %s", l.Name, runtime.Canonical(data))
		}
		application = runtime.SiteApp(data)
		f.SiteApp = application
	}
	if err := layTranslations(application, filepath.Join(files, "translations")); err != nil {
		return openedSite{}, err
	}
	return openedSite{application, f, staticURL}, nil
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

// servedHub reads the secrets of the hub served at url. A state no start has
// served holds no secrets, and drush creates none: the hub is nil then.
func servedHub(dir state.Dir, url string) (*caddyfile.Mercure, error) {
	publisher, subscriber, ok, err := dir.MercureSecrets()
	if err != nil || !ok {
		return nil, err
	}
	return &caddyfile.Mercure{URL: url, Publisher: publisher, Subscriber: subscriber}, nil
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
func (f engineProject) drush(facts Facts, k Kind, words []string) (Command, error) {
	if bundle, ok := f.entry.Bundle(); ok {
		facts.CAFile = bundle
	}
	if f.composer.Mercure {
		a, err := Served(f.entry, facts.Name, engineTarget(), state.Listener{})
		if err != nil {
			return Command{}, err
		}
		if a.Recorded {
			if facts.Hub, err = servedHub(f.entry, a.Mercure()); err != nil {
				return Command{}, err
			}
		}
	}
	script := filepath.Join(f.project, runtime.DrushScript)
	if info, err := os.Stat(script); err != nil || !info.Mode().IsRegular() {
		return Command{}, fmt.Errorf("%s has no Drush: %s does not exist. Run composer require drush/drush in it.", runtime.Canonical(f.project), runtime.Canonical(script))
	}
	return drushCall(facts, k, facts.Application, script, words), nil
}

func engineDrush(facts Facts, c command, cwd string) (Command, error) {
	f, err := openFolder(facts.Launch, cwd)
	if err != nil {
		return Command{}, err
	}
	return f.drush(facts, Drush, c.words)
}

// vendorRoot finds the project holding cwd: the nearest directory at or above
// it with a vendor/autoload.php. Drupal core and many modules carry a
// composer.json and no vendor/. The autoloader runs as this user, so the first
// one found must belong, with its folders, to this user, the system or one of
// this user's groups on Unix. A link at
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

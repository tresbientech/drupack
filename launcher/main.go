// Command launcher unpacks its embedded runtime into the user's cache and
// runs it. launcher/cmd/pack builds one launcher per runtime build.
package main

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/tls"
)

func main() {
	if err := run(); err != nil {
		if !errors.Is(err, errReported) {
			fmt.Fprintln(os.Stderr, err)
		}
		holdConsole()
		os.Exit(1)
	}
}

func run() error {
	if len(os.Args) > 1 && os.Args[1] == "lay-app" {
		return layApp(os.Args[2:])
	}
	// detach needs no cache.
	if len(os.Args) > 1 && os.Args[1] == "detach" {
		return detach(os.Args[2:])
	}
	tlsFolder, err := tlsDir()
	if err != nil {
		return err
	}
	// trust may ask for a password.
	if len(os.Args) > 1 && os.Args[1] == "trust" {
		return tls.Trust(os.Stdout, tlsFolder, os.Args[2:])
	}
	launcher, err := os.Executable()
	if err != nil {
		return err
	}
	// A self-update renames the file a symlink on PATH names, so the old file it
	// moves aside sits beside that one.
	executable := launcher
	if engine {
		if executable, err = filepath.EvalSymlinks(launcher); err != nil {
			return err
		}
		removeReplaced(executable)
	}
	if len(os.Args) > 1 && os.Args[1] == "self-update" {
		return selfUpdate(os.Args[2:], releaseURL, executable, os.Stdout)
	}
	root, err := runtime.Root(siteName, os.Stderr)
	if err != nil {
		return err
	}
	cache := runtime.NewCache(root, runtime.HoldUsage)
	if len(os.Args) > 1 && os.Args[1] == "clean" {
		dry, err := cleanArguments(os.Args[2:])
		if err != nil {
			return err
		}
		return cache.Clean(siteName, dry, os.Stdout)
	}
	if engine {
		updateNotice(root, executable, os.Stderr, runtime.Terminal(os.Stderr), time.Now(), func() error { return startCheck(launcher) })
	}
	c, err := parse(os.Args, engine, os.LookupEnv)
	if err != nil {
		return err
	}
	// A stop unpacks nothing.
	if c.word == "stop" {
		return runStop(c, root, os.Stdout)
	}
	m, err := runtime.ParseManifest(runtimeManifest)
	if err != nil {
		return err
	}
	directory, application, err := cache.PrepareRelease(runtime.Release{
		Payload:     runtimePayload,
		Manifest:    m,
		AppChecksum: string(appChecksum),
		AppPayload:  appPayload,
	}, os.Stderr)
	if err != nil {
		return err
	}
	nodeBin, err := prepareNode(cache)
	if err != nil {
		return err
	}
	l := Launch{
		// os.Args, not the resolved executable path, keeps argv[0] the path the reader invoked.
		Args:         os.Args,
		Parent:       os.Environ(),
		Entry:        filepath.Join(directory, m.Entry),
		Application:  application,
		Launcher:     launcher,
		AppChecksum:  string(appChecksum),
		Root:         root,
		NodeBin:      nodeBin,
		TLSDir:       tlsFolder,
		Name:         siteName,
		Version:      siteVersion,
		Components:   siteComponents,
		Engine:       engine,
		ConsoleOwned: consoleOwned(),
		FoldCase:     foldCase,
	}
	switch {
	case c.word == "help":
		fmt.Println(helpText())
		return nil
	case c.word == "node" && nodeBin == "":
		return nodeRefusal(application, os.Args[1])
	case c.word == "start" && engine:
		return startEngine(l, c)
	case c.word == "start":
		return startSite(l, c)
	case c.word == "drush", engine && (c.word == "export" || c.word == "dr"):
		return runInFolder(l, c)
	}
	return launch(Plan(l))
}

// runInFolder runs drush, or the engine's export or dr, from the reader's directory.
func runInFolder(l Launch, c command) error {
	cwd, err := os.Getwd()
	if err != nil {
		return err
	}
	var call Command
	switch {
	case c.word == "drush":
		call, err = drushPlan(l, c, cwd)
	case c.word == "dr":
		call, err = engineCore(l, c, cwd, environ{entries: environment(l), fold: l.FoldCase})
	default:
		var export exportCall
		export, err = engineExport(l, c, cwd, environ{entries: environment(l), fold: l.FoldCase})
		if err == nil {
			err = export.requireTomeStatic()
		}
		call = export.run
	}
	if err != nil {
		return err
	}
	return launch(call)
}

// nodeRefusal answers a Node word on a file that carries no Node. A site whose
// contract names Node was packed without it for this libc.
func nodeRefusal(application, word string) error {
	site, err := siteconfig.Load(application)
	if err != nil {
		return err
	}
	if site.Node == "" {
		return fmt.Errorf("Unknown command: %s\n\n%s", word, siteHelpText())
	}
	return fmt.Errorf("%s: this musl build of %s carries no Node. Run the glibc build, the file without -musl in its name.", word, siteName)
}

// prepareNode returns "" for a file that carries no Node.
func prepareNode(cache runtime.Cache) (string, error) {
	if len(nodeManifest) == 0 {
		return "", nil
	}
	m, err := runtime.ParseManifest(nodeManifest)
	if err != nil {
		return "", err
	}
	directory, err := cache.PrepareNode(nodePayload, m, os.Stderr)
	if err != nil {
		return "", err
	}
	return filepath.Dir(filepath.Join(directory, filepath.FromSlash(m.Entry))), nil
}

// tlsDir is the folder holding the user's local root: a caller's DRUPACK_TLS_DIR,
// else the system's. The runtime runs from the application directory, so a
// relative value is made absolute against the reader's directory here. A caller
// who sets it empty names no folder.
func tlsDir() (string, error) {
	if dir, set := os.LookupEnv("DRUPACK_TLS_DIR"); set {
		if dir == "" {
			return "", nil
		}
		return filepath.Abs(dir)
	}
	return runtime.TLSDir(), nil
}

func cleanArguments(arguments []string) (bool, error) {
	switch {
	case len(arguments) == 0:
		return false, nil
	case len(arguments) == 1 && arguments[0] == "--dry-run":
		return true, nil
	}
	return false, fmt.Errorf("clean takes --dry-run alone")
}

// layApp lays the site's own application in Site data, or with --check exits 1
// when the application there belongs to another release.
func layApp(arguments []string) error {
	data, writable, check, err := runtime.LayArguments(arguments)
	if err != nil {
		return err
	}
	if check {
		if !runtime.SiteAppCurrent(data, string(appChecksum)) {
			os.Exit(1)
		}
		return nil
	}
	return runtime.LaySiteApp(data, string(appChecksum), appPayload, writable, os.Stderr)
}

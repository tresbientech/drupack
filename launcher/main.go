// Command launcher unpacks its embedded runtime into the user's cache and
// runs it. launcher/cmd/pack builds one launcher per runtime build.
package main

import (
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, err)
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
	if engine && len(os.Args) > 1 && os.Args[1] == "composer-settings" {
		return composerSettings(os.Args[2:], os.Stdout)
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
	return launch(Plan(Launch{
		// os.Args, not the resolved executable path, keeps argv[0] the path the reader invoked.
		Args:         os.Args,
		Parent:       os.Environ(),
		Entry:        filepath.Join(directory, m.Entry),
		Application:  application,
		Launcher:     launcher,
		Root:         root,
		NodeBin:      nodeBin,
		TLSDir:       runtime.TLSDir(),
		Name:         siteName,
		Version:      siteVersion,
		Components:   siteComponents,
		Engine:       engine,
		ConsoleOwned: consoleOwned(),
		FoldCase:     foldCase,
	}))
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
// composerSettings prints, as one JSON object, the settings engine/serve.php serves
// the folder with.
func composerSettings(arguments []string, out io.Writer) error {
	if len(arguments) != 1 {
		return fmt.Errorf("composer-settings takes one folder, not %q", arguments)
	}
	settings, err := siteconfig.ReadComposer(arguments[0], siteconfig.Folder)
	if err != nil {
		return err
	}
	return json.NewEncoder(out).Encode(settings)
}

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

// Command launcher unpacks its embedded runtime into the user's cache and
// runs it. launcher/cmd/pack builds one launcher per runtime build.
package main

import (
	"fmt"
	"os"
	"path/filepath"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		holdConsole()
		os.Exit(1)
	}
}

func run() error {
	// launch.php runs this word under the Serving lease, once it has resolved Site data.
	if len(os.Args) > 1 && os.Args[1] == "lay-app" {
		return layApp(os.Args[2:])
	}
	// launch.php and serve.php run this word to start the server in the background. It needs
	// no cache.
	if len(os.Args) > 1 && os.Args[1] == "detach" {
		return detach(os.Args[2:])
	}
	root, err := runtime.Root(siteName, os.Stderr)
	if err != nil {
		return err
	}
	if len(os.Args) > 1 && os.Args[1] == "clean" {
		dry, err := cleanArguments(os.Args[2:])
		if err != nil {
			return err
		}
		if err := runtime.CleanApps(root, siteName, dry, os.Stdout); err != nil {
			return err
		}
		return runtime.CleanNode(root, dry, os.Stdout)
	}
	m, err := runtime.ParseManifest(runtimeManifest)
	if err != nil {
		return err
	}
	directory, application, err := runtime.PrepareRelease(root, runtime.Release{
		Payload:     runtimePayload,
		Manifest:    m,
		AppChecksum: string(appChecksum),
		AppPayload:  appPayload,
	}, os.Stderr, runtime.HoldUsage)
	if err != nil {
		return err
	}
	if err := prepareNode(root); err != nil {
		return err
	}
	// The runtime and launch.php are shared by every site built on this engine, so
	// the site's name and release reach them from here.
	if err := os.Setenv("DRUPACK_RUNTIME_NAME", siteName); err != nil {
		return err
	}
	if err := os.Setenv("DRUPACK_RUNTIME_SITE_VERSION", siteVersion); err != nil {
		return err
	}
	if err := os.Setenv("DRUPACK_RUNTIME_COMPONENTS", siteComponents); err != nil {
		return err
	}
	// launch.php runs this executable again to lay a site's own application, and to
	// detach a server, as serve.php does for a folder.
	launcher, err := os.Executable()
	if err != nil {
		return err
	}
	if err := os.Setenv("DRUPACK_RUNTIME_LAUNCHER", runtime.Canonical(launcher)); err != nil {
		return err
	}
	// A start that detaches tells the reader how to stop the site, in the words the
	// reader used to run it.
	if err := os.Setenv("DRUPACK_RUNTIME_INVOKED", os.Args[0]); err != nil {
		return err
	}
	// The engine executable serves a folder named from the reader's own directory,
	// so the runtime gets no application to change into. An inherited directory
	// would send it down the site path, which refuses the php word.
	if engine {
		if err := os.Unsetenv("DRUPACK_RUNTIME_APP_DIR"); err != nil {
			return err
		}
		// serve.php keeps each served folder's lease, stop record and log in an entry here.
		if err := os.Setenv("DRUPACK_RUNTIME_CACHE_ROOT", runtime.Canonical(root)); err != nil {
			return err
		}
		return launch(filepath.Join(directory, m.Entry), runtimeArguments(os.Args, application, engine, len(nodeManifest) > 0))
	}
	// The server resolves the site from its working directory, which the entry
	// point sets from this variable once it starts. PHP, Caddy and the reader's
	// terminal read the exported value, so it takes Drupack's canonical form;
	// application itself stays native for the join below.
	if err := os.Setenv("DRUPACK_RUNTIME_APP_DIR", runtime.Canonical(application)); err != nil {
		return err
	}
	// os.Args, not the resolved executable path, keeps argv[0] the path the reader invoked.
	return launch(filepath.Join(directory, m.Entry), os.Args)
}

// prepareNode unpacks the Node release this file carries and puts its
// executable directory first on PATH, for the server, Drush and the node, npm
// and npx commands.
func prepareNode(root string) error {
	// A Drupack site that carries Node can start one that does not, which
	// would otherwise inherit the first one's release.
	if len(nodeManifest) == 0 {
		return os.Unsetenv("DRUPACK_RUNTIME_NODE")
	}
	m, err := runtime.ParseManifest(nodeManifest)
	if err != nil {
		return err
	}
	directory, err := runtime.PrepareNode(root, nodePayload, m, os.Stderr, runtime.HoldUsage)
	if err != nil {
		return err
	}
	executables := filepath.Dir(filepath.Join(directory, filepath.FromSlash(m.Entry)))
	if err := os.Setenv("PATH", executables+string(os.PathListSeparator)+os.Getenv("PATH")); err != nil {
		return err
	}
	return os.Setenv("DRUPACK_RUNTIME_NODE", runtime.Canonical(executables))
}

// cleanArguments reads what follows the clean command, which a reader types.
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

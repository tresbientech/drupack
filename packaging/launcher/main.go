// Command launcher unpacks its embedded runtime into the user's cache and
// runs it. packaging/launcher/cmd/pack builds one launcher per runtime build.
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
	m, err := runtime.ParseManifest(manifestData)
	if err != nil {
		return err
	}
	root, err := runtime.Root(os.Stderr)
	if err != nil {
		return err
	}
	if len(os.Args) > 1 && os.Args[1] == "clean" {
		dry, err := cleanArguments(os.Args[2:])
		if err != nil {
			return err
		}
		return runtime.CleanApps(root, dry, os.Stdout)
	}
	directory, err := runtime.Prepare(root, payload, m, os.Stderr)
	if err != nil {
		return err
	}
	application, err := runtime.PrepareApp(root, string(appChecksum), appPayload, os.Stderr)
	if err != nil {
		return err
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

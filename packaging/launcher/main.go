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
	root, err := runtime.Root()
	if err != nil {
		return err
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
	// point sets from this variable once it starts.
	if err := os.Setenv("DRUPACK_RUNTIME_APP_DIR", application); err != nil {
		return err
	}
	// os.Args, not the resolved executable path, keeps argv[0] the path the reader invoked.
	return launch(filepath.Join(directory, m.Entry), os.Args)
}

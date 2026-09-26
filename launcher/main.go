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

// embeddedRuntime is one runtime build this launcher carries: the libc it was
// linked against, its compressed payload, and the manifest describing what the
// payload holds. payload.go declares the builds a packed launcher holds.
type embeddedRuntime struct {
	libc     string
	payload  []byte
	manifest []byte
}

func run() error {
	// launch.php runs this word under the Serving lease, once it has resolved Site data.
	if len(os.Args) > 1 && os.Args[1] == "lay-app" {
		return layApp(os.Args[2:])
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
		return runtime.CleanApps(root, siteName, dry, os.Stdout)
	}
	selected, m, err := selectRuntime()
	if err != nil {
		return err
	}
	directory, err := runtime.Prepare(root, selected.payload, m, os.Stderr)
	if err != nil {
		return err
	}
	application, err := runtime.PrepareApp(root, string(appChecksum), appPayload, os.Stderr)
	if err != nil {
		return err
	}
	// Both directories stay in use until this process ends, which on unix is the
	// exec below and on Windows the wait for the child. Cleanup reads the markers
	// and leaves a running site's files alone.
	runtime.HoldUsage(directory)
	runtime.HoldUsage(application)
	// The runtime and launch.php are shared by every site built on this engine, so
	// the site's name and release reach them from here.
	if err := os.Setenv("DRUPACK_RUNTIME_NAME", siteName); err != nil {
		return err
	}
	if err := os.Setenv("DRUPACK_RUNTIME_SITE_VERSION", siteVersion); err != nil {
		return err
	}
	// The engine executable serves a folder named from the reader's own directory,
	// so the runtime gets no application to change into.
	if engine {
		return launch(filepath.Join(directory, m.Entry), engineArguments(application))
	}
	// The server resolves the site from its working directory, which the entry
	// point sets from this variable once it starts. PHP, Caddy and the reader's
	// terminal read the exported value, so it takes Drupack's canonical form;
	// application itself stays native for the join below.
	if err := os.Setenv("DRUPACK_RUNTIME_APP_DIR", runtime.Canonical(application)); err != nil {
		return err
	}
	// launch.php runs this executable again to lay a site's own application.
	launcher, err := os.Executable()
	if err != nil {
		return err
	}
	if err := os.Setenv("DRUPACK_RUNTIME_LAUNCHER", runtime.Canonical(launcher)); err != nil {
		return err
	}
	// os.Args, not the resolved executable path, keeps argv[0] the path the reader invoked.
	return launch(filepath.Join(directory, m.Entry), os.Args)
}

// selectRuntime returns the runtime build to unpack and its parsed manifest.
// Every carried manifest is parsed first, since the interpreter each one
// records decides which builds this host can run.
func selectRuntime() (embeddedRuntime, runtime.Manifest, error) {
	choices := make([]runtime.Choice, len(runtimes))
	manifests := make([]runtime.Manifest, len(runtimes))
	for index, carried := range runtimes {
		m, err := runtime.ParseManifest(carried.manifest)
		if err != nil {
			return embeddedRuntime{}, runtime.Manifest{}, err
		}
		manifests[index] = m
		choices[index] = runtime.Choice{Libc: carried.libc, Interpreter: m.Interpreter}
	}
	index, err := runtime.Select(choices, os.Getenv(runtime.LibcVariable))
	if err != nil {
		return embeddedRuntime{}, runtime.Manifest{}, err
	}
	return runtimes[index], manifests[index], nil
}

// engineArguments turns the reader's words into the runtime's: `php` runs PHP
// itself, the version flags reach the runtime, and every other word reaches
// serve.php.
func engineArguments(application string) []string {
	if len(os.Args) > 1 && os.Args[1] == "php" {
		return append([]string{os.Args[0], "php-cli"}, os.Args[2:]...)
	}
	if len(os.Args) == 2 && (os.Args[1] == "--version" || os.Args[1] == "-v") {
		return os.Args
	}
	return append([]string{os.Args[0], "php-cli", filepath.Join(application, "serve.php")}, os.Args[1:]...)
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

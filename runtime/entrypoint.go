package main

import (
	"context"
	"fmt"
	"net/url"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"
	"time"

	"github.com/dunglas/frankenphp"
)

// version names the engine release. Each build script sets it with -ldflags.
var version = "dev"

// libc names the C library this runtime was linked against. embed.sh sets it
// from the builder image's SPC_LIBC, and a build that links no libc of its own
// leaves it empty. A Linux executable carries a runtime per libc, so a report
// about one of them has to say which ran.
var libc = ""

// siteName names the executable the reader ran. The launcher exports it with
// the site's release, since one runtime build serves every site of an engine
// release.
func siteName() string {
	return os.Getenv("DRUPACK_RUNTIME_NAME")
}

// release describes what ran: the site and its release, the engine release,
// and the libc when more than one build of this release exists.
func release() string {
	site := siteName() + " " + os.Getenv("DRUPACK_RUNTIME_SITE_VERSION")
	if libc == "" {
		return site + " (drupack " + version + ")"
	}
	return site + " (drupack " + version + ", " + libc + ")"
}

const usage = `Usage: %[1]s [OPTIONS]
       %[1]s drush [OPTIONS] DRUSH_COMMAND
       %[1]s clean [--dry-run]

Options:
  --data-dir PATH            Site data directory, ./data by default
  --listen IP:PORT           Listener address, 127.0.0.1 on the site's port by default
  --host HOST                Permitted request host, localhost by default
  --files-dir PATH           Public files directory, files in Site data by default
  --database sqlite|mysql|pgsql
                             Database backend for a first start, sqlite by default
  --db-host, --db-port, --db-name, --db-user, --db-password
                             Connection details for mysql and pgsql
  --admin-user, --admin-password
                             Administrator account for a first start. Without them
                             a first start creates admin and prints a one-time
                             login link.
  --site-name NAME           Site name for a first start, the packaged site's name by default
  --no-browser               Do not open a browser
  --version, --help

Commands:
  drush                      Run a Drush command against the site
  clean                      Remove the unpacked applications from the cache.
                             --dry-run lists them and removes nothing.

Examples:
  %[1]s
  %[1]s --data-dir ./site --listen 127.0.0.1:9000
  %[1]s --admin-user admin --admin-password 'choose-a-password'
  %[1]s drush --data-dir ./site status
  %[1]s drush --data-dir ./site user:login
  %[1]s clean --dry-run`

// readinessPath answers 204 for a request carrying this site's own identity token, and 404
// for anything else. The Caddyfile serves it without reaching Drupal, so it
// costs the readiness poll in watch.go no page render and no session.
const readinessPath = "/.drupack-id?id="

func openBrowser(address string) {
	var command *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		command = exec.Command("rundll32", "url.dll,FileProtocolHandler", address)
	case "darwin":
		command = exec.Command("open", address)
	default:
		command = exec.Command("xdg-open", address)
	}
	_ = command.Start()
}

// A healthy stop takes about three seconds.
const shutdownDeadline = 10 * time.Second

// stopGuard runs only for a server. Notifying on these signals suppresses Go's own
// termination, which a command that handles neither still needs, and guard in
// watch.go forces the exit instead once the deadline passes.
func stopGuard() context.Context {
	signals := make(chan os.Signal, 1)
	signal.Notify(signals, os.Interrupt, syscall.SIGTERM)
	return guard(signals, shutdownDeadline, func(code int) {
		fmt.Fprintf(os.Stderr, "%s did not stop within %s. Forcing exit.\n", siteName(), shutdownDeadline)
		os.Exit(code)
	})
}

// cronInterval is the period automated_cron ships with, whose in-request run the
// packaged settings turn off. cronFirstDelay holds the first run back, so a
// reader's opening pages, which compile the container and render uncached, do
// not share the database with it.
const cronInterval = 3 * time.Hour
const cronFirstDelay = 2 * time.Minute

// serveCaddyfile runs Caddy on the Caddyfile at config. PHPRC already names
// the runtime directory in every hop, including this one, so php.ini loads
// from there without a scan path naming the application too.
func serveCaddyfile(config string) {
	os.Args = []string{os.Args[0], "run", "--config", config, "--adapter", "caddyfile"}
}

// canonical rewrites path in Drupack's canonical form: forward slashes. This
// file builds inside frankenphp's own module, which cannot import the
// launcher's internal/runtime package, so the conversion is restated here.
// filepath.ToSlash does the same work as the launcher's own copy, which its
// canonical_test.go covers.
func canonical(path string) string {
	return filepath.ToSlash(path)
}

func init() {
	executable, err := os.Executable()
	if err != nil {
		panic(err)
	}
	// PHP, Caddy and the reader's terminal read this variable, so it takes
	// Drupack's canonical form; executable itself is used for nothing else.
	if err := os.Setenv("DRUPACK_RUNTIME_BINARY", canonical(executable)); err != nil {
		panic(err)
	}
	// The launcher unpacks the application once per release and names its
	// directory here. Every relative path the site resolves starts from it.
	application := os.Getenv("DRUPACK_RUNTIME_APP_DIR")
	if application == "" {
		application = frankenphp.EmbeddedAppPath
	}
	// A build step runs this binary on its own to read its version, with no
	// launcher to name a directory and no embedded application to fall back to.
	if application != "" {
		// The site resolves its own relative paths from the application, so the
		// directory the reader started in has to reach launch.php separately.
		// Site data named relative to it belongs there, not in a copy every
		// site of the release shares.
		if directory, err := os.Getwd(); err == nil {
			// launch.php reads this variable to resolve a relative --data-dir
			// against the reader's own directory, so it takes the canonical form.
			if err := os.Setenv("DRUPACK_RUNTIME_CWD", canonical(directory)); err != nil {
				panic(err)
			}
		}
		if err := os.Chdir(application); err != nil {
			panic(err)
		}
	}
	// launch.php replaces itself with this command to serve the site, naming the
	// Caddyfile it wrote into Site data.
	if len(os.Args) == 3 && os.Args[1] == "php-server" {
		// The server waits for itself. A separate process would first extract its own copy
		// of the embedded application, which takes longer than the wait on a slow disk.
		go run(stopGuard(), siteName(), plan{
			probe: strings.TrimSuffix(os.Getenv("DRUPACK_RUNTIME_URL"), "/") +
				readinessPath + url.QueryEscape(os.Getenv("DRUPACK_RUNTIME_ID")),
			open: os.Getenv("DRUPACK_RUNTIME_OPEN"),
			cron: []string{executable, "php-cli",
				filepath.Join(application, "vendor", "drush", "drush", "drush.php"), "cron"},
			dir:         application,
			poll:        500 * time.Millisecond,
			readyWithin: 2 * time.Minute,
			firstCron:   cronFirstDelay,
			cronEvery:   cronInterval,
		}, os.Stdout, os.Stderr, openBrowser)
		serveCaddyfile(os.Args[2])
		return
	}
	// The engine executable's serve.php replaces itself with this command, naming its
	// Caddyfile. The folder keeps automated_cron, which runs inside a request after
	// its response, so a stop meets a running PHP request as the site's server does.
	if len(os.Args) == 3 && os.Args[1] == "folder-server" {
		stopGuard()
		serveCaddyfile(os.Args[2])
		return
	}
	// launch.php replaces itself with this command when its Site data is already served,
	// since a handover starts no server to open the browser from. The target is a working
	// credential, so it arrives in the environment, which only this user can read.
	if len(os.Args) > 1 && os.Args[1] == "browser-open" {
		openBrowser(os.Getenv("DRUPACK_RUNTIME_OPEN"))
		os.Exit(0)
	}
	launchScript := filepath.Join(application, "launch.php")
	if len(os.Args) > 1 && os.Args[1] == "drush" {
		if err := os.Setenv("DRUPACK_RUNTIME_DRUSH", "1"); err != nil {
			panic(err)
		}
		os.Args = append([]string{os.Args[0], "php-cli", launchScript}, os.Args[2:]...)
		return
	}
	if len(os.Args) == 2 && (os.Args[1] == "--help" || os.Args[1] == "-h") {
		fmt.Printf(usage+"\n", siteName())
		os.Exit(0)
	}
	if len(os.Args) == 2 && (os.Args[1] == "--version" || os.Args[1] == "-v") {
		fmt.Println(release())
		os.Exit(0)
	}
	if len(os.Args) == 1 || strings.HasPrefix(os.Args[1], "-") {
		os.Args = append([]string{os.Args[0], "php-cli", launchScript}, os.Args[1:]...)
		return
	}
	// FrankenPHP's own command line takes every remaining word. Drupack and its builds
	// use php-cli and version there, and any other word would answer with Caddy's usage.
	if os.Args[1] != "php-cli" && os.Args[1] != "version" {
		fmt.Fprintf(os.Stderr, "Unknown command: %s\n\n", os.Args[1])
		fmt.Fprintf(os.Stderr, usage+"\n", siteName())
		os.Exit(1)
	}
}

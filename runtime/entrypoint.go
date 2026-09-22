package main

import (
	"context"
	"fmt"
	"net/http"
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

// version names the release. Each build script sets it with -ldflags.
var version = "dev"

// libc names the C library this runtime was linked against. embed.sh sets it
// from the builder image's SPC_LIBC, and a build that links no libc of its own
// leaves it empty. A Linux executable carries a runtime per libc, so a report
// about one of them has to say which ran.
var libc = ""

// release describes what ran: the version, and the libc when more than one
// build of this release exists.
func release() string {
	if libc == "" {
		return "Drupack " + version
	}
	return "Drupack " + version + " (" + libc + " runtime)"
}

const usage = `Usage: drupack [OPTIONS]
       drupack dr [OPTIONS] DRUSH_COMMAND
       drupack clean [--dry-run]

Options:
  --data-dir PATH            Site data directory, ./data by default
  --listen IP:PORT           Listener address, 127.0.0.1 on the site's port by default
  --host HOST                Permitted request host, localhost by default
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
  dr                         Run a Drush command against the site
  clean                      Remove the unpacked applications from the cache.
                             --dry-run lists them and removes nothing.

Examples:
  drupack
  drupack --data-dir ./site --listen 127.0.0.1:9000
  drupack --admin-user admin --admin-password 'choose-a-password'
  drupack dr --data-dir ./site status
  drupack dr --data-dir ./site user:login
  drupack clean --dry-run`

// readinessPath answers 204 for a request carrying this site's own identity token, and 404
// for anything else. The Caddyfile serves it without reaching Drupal, so the poll below
// costs no page render and no session.
const readinessPath = "/.drupack-id?id="

// openWhenReady waits for the site to answer at address, then reports readiness, closes
// ready and opens the browser on target when asked. The terminal keeps one readiness
// line even when no browser opens. The target is a one-time login link, which a request spends, so the poll
// asks for the readiness path instead. launch.php passes both through the environment.
//
// Only 204 from that path counts. A 500 from a failed bootstrap and a 400 from a rejected
// host both reach a client that connects, so a poll accepting any answer would announce a
// site nobody can use.
func openWhenReady(address string, token string, target string, browser bool, ready chan<- struct{}) {
	// A cold start answers its first request slowly, and a request that never returns would
	// otherwise hold the poll past the deadline.
	client := http.Client{Timeout: 30 * time.Second}
	probe := strings.TrimSuffix(address, "/") + readinessPath + url.QueryEscape(token)
	started := time.Now()
	deadline := started.Add(2 * time.Minute)
	var last error
	for time.Now().Before(deadline) {
		response, err := client.Get(probe)
		if err == nil {
			response.Body.Close()
			if response.StatusCode == http.StatusNoContent {
				fmt.Println("\nDrupack is ready. Press Ctrl+C to stop.")
				close(ready)
				if browser {
					openBrowser(target)
				}
				return
			}
			err = fmt.Errorf("the server answered %s", response.Status)
		}
		last = err
		time.Sleep(500 * time.Millisecond)
	}
	// The site keeps serving, so this names what the wait saw rather than stopping anything.
	fmt.Fprintf(os.Stderr, "Drupal did not answer at %s within %s: %v\n",
		address, time.Since(started).Round(time.Second), last)
}

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

// Caddy finishes an in-flight PHP request before it exits, and a request has no
// upper bound, so one that never returns holds the process open and Ctrl+C never
// lands. The deadline starts at the first signal and fires only when the ordinary
// shutdown has not finished by then; a healthy one takes about three seconds.
const shutdownDeadline = 10 * time.Second

// forceExitOnStalledShutdown runs only for the server. Notifying on these signals
// suppresses Go's own termination, which a command that handles neither still needs.
// The returned context ends at the first signal, so work this process started
// outside a request stops before the deadline below fires.
func forceExitOnStalledShutdown() context.Context {
	signals := make(chan os.Signal, 1)
	signal.Notify(signals, os.Interrupt, syscall.SIGTERM)
	stopping, stop := context.WithCancel(context.Background())
	go func() {
		<-signals
		stop()
		time.Sleep(shutdownDeadline)
		fmt.Fprintf(os.Stderr, "Drupack did not stop within %s. Forcing exit.\n", shutdownDeadline)
		os.Exit(1)
	}()
	return stopping
}

// cronInterval is the period automated_cron ships with, whose in-request run the
// packaged settings turn off. cronFirstDelay holds the first run back, so a
// reader's opening pages, which compile the container and render uncached, do
// not share the database with it.
const cronInterval = 3 * time.Hour
const cronFirstDelay = 2 * time.Minute

// runScheduledWork runs Drupal's cron in a child process for as long as this
// server serves. automated_cron runs the same work inside whichever request
// arrives after its interval elapses, where a fetch that cannot reach
// drupal.org, or a queue that takes a minute, holds that request's PHP thread
// and the database rows behind it. The context ends the child at the first
// shutdown signal, so a run in flight cannot hold the process open.
func runScheduledWork(stopping context.Context, executable string, application string, ready <-chan struct{}) {
	select {
	case <-ready:
	case <-stopping.Done():
		return
	}
	timer := time.NewTimer(cronFirstDelay)
	defer timer.Stop()
	for {
		select {
		case <-timer.C:
		case <-stopping.Done():
			return
		}
		cron := exec.CommandContext(stopping, executable, "php-cli",
			filepath.Join(application, "vendor", "drush", "drush", "drush.php"), "cron")
		cron.Dir = application
		// A failed run is reported and the next one still runs: cron carries
		// search indexing and queue work, which a later run picks up.
		if output, err := cron.CombinedOutput(); err != nil && stopping.Err() == nil {
			fmt.Fprintf(os.Stderr, "Scheduled work did not finish: %v\n%s", err, output)
		}
		timer.Reset(cronInterval)
	}
}

// serveApplication runs the application's own Caddyfile. PHPRC already names
// the runtime directory in every hop, including this one, so php.ini loads
// from there without a scan path naming the application too.
func serveApplication(application string) {
	os.Args = []string{
		os.Args[0], "run",
		"--config", filepath.Join(application, "Caddyfile"),
		"--adapter", "caddyfile",
	}
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
	// launch.php replaces itself with this command to serve the site.
	if len(os.Args) > 1 && os.Args[1] == "php-server" {
		stopping := forceExitOnStalledShutdown()
		ready := make(chan struct{})
		// The server waits for itself. A separate process would first extract its own copy
		// of the embedded application, which takes longer than the wait on a slow disk.
		go openWhenReady(os.Getenv("DRUPACK_RUNTIME_URL"), os.Getenv("DRUPACK_RUNTIME_ID"),
			os.Getenv("DRUPACK_RUNTIME_OPEN"), os.Getenv("DRUPACK_RUNTIME_BROWSER") == "1", ready)
		go runScheduledWork(stopping, executable, application, ready)
		serveApplication(application)
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
	if len(os.Args) > 1 && os.Args[1] == "dr" {
		if err := os.Setenv("DRUPACK_RUNTIME_DRUSH", "1"); err != nil {
			panic(err)
		}
		os.Args = append([]string{os.Args[0], "php-cli", launchScript}, os.Args[2:]...)
		return
	}
	if len(os.Args) == 2 && (os.Args[1] == "--help" || os.Args[1] == "-h") {
		fmt.Println(usage)
		os.Exit(0)
	}
	if len(os.Args) == 2 && (os.Args[1] == "--version" || os.Args[1] == "-v") {
		fmt.Println(release())
		os.Exit(0)
	}
	if len(os.Args) == 1 || strings.HasPrefix(os.Args[1], "-") {
		os.Args = append([]string{os.Args[0], "php-cli", launchScript}, os.Args[1:]...)
	}
}

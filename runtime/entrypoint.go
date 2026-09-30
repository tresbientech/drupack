package main

import (
	"context"
	"encoding/json"
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

	"github.com/caddyserver/caddy/v2"
	"github.com/dunglas/frankenphp"
)

// version names the engine release. Each build script sets it with -ldflags.
var version = "dev"

// libc names the C library this runtime was linked against. embed.sh sets it
// from the builder image's SPC_LIBC, and a build that links no libc of its own
// leaves it empty. A Linux release publishes a file per libc, so a report
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

const usage = `Usage: %[1]s [start] [OPTIONS]
       %[1]s drush [OPTIONS] DRUSH_COMMAND
       %[1]s node|npm|npx [ARGUMENTS]
       %[1]s stop [--data-dir PATH]
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
  --foreground               Serve in this terminal until a signal stops the site
  --version, --help

Commands:
  start                      Start the site, which a bare command does too
  drush                      Run a Drush command against the site
  stop                       Stop the site serving the Site data, for a site
                             another terminal started
  node, npm, npx             Run the site's bundled Node release, for a site
                             that carries one
  clean                      Remove the unpacked applications from the cache.
                             --dry-run lists them and removes nothing.

Examples:
  %[1]s
  %[1]s --data-dir ./site --listen 127.0.0.1:9000
  %[1]s --admin-user admin --admin-password 'choose-a-password'
  %[1]s drush --data-dir ./site status
  %[1]s drush --data-dir ./site user:login
  %[1]s clean --dry-run`

// nodeCommands names the programs of a site's bundled Node release that its
// command line runs, with each one's file on Windows, where npm and npx are
// batch files.
var nodeCommands = map[string]string{"node": "node.exe", "npm": "npm.cmd", "npx": "npx.cmd"}

// runNode runs program from the Node release the launcher unpacked, in the
// reader's directory, and exits with its status. It returns for a site that
// carries no Node, whose command line has no such word.
func runNode(program string, arguments []string) {
	directory := os.Getenv("DRUPACK_RUNTIME_NODE")
	if directory == "" {
		if !siteCarriesNode() {
			return
		}
		fmt.Fprintf(os.Stderr, "%s: this musl build of %s carries no Node. Run the glibc build, the file without -musl in its name.\n",
			program, siteName())
		os.Exit(1)
	}
	file := program
	if runtime.GOOS == "windows" {
		file = nodeCommands[program]
	}
	command := exec.Command(filepath.Join(directory, file), arguments...)
	command.Dir = os.Getenv("DRUPACK_RUNTIME_CWD")
	command.Stdin, command.Stdout, command.Stderr = os.Stdin, os.Stdout, os.Stderr
	// The terminal interrupts the whole process group, the program included, so
	// this process waits for the program and passes on a termination alone.
	signals := make(chan os.Signal, 1)
	signal.Notify(signals, os.Interrupt, syscall.SIGTERM)
	if err := command.Start(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	go func() {
		for received := range signals {
			if received != os.Interrupt {
				command.Process.Signal(received)
			}
		}
	}()
	command.Wait()
	code := command.ProcessState.ExitCode()
	// A program a signal ended reports -1.
	if code < 0 {
		code = 1
	}
	os.Exit(code)
}

// siteCarriesNode reads the site.json of the application this process runs
// from, the working directory by now, for the Node release the build recorded.
func siteCarriesNode() bool {
	content, err := os.ReadFile("site.json")
	if err != nil {
		panic(err)
	}
	var site struct {
		Node string `json:"node"`
	}
	if err := json.Unmarshal(content, &site); err != nil {
		panic(err)
	}
	return site.Node != ""
}

const phpUsage = `Usage: %[1]s php SCRIPT [ARGUMENTS]
       %[1]s php -r CODE`

// phpArguments turns the Engine executable's php command into php-cli's, which
// reads no PHP option and passes -r code no $argv. The arguments come from the
// reader or from Drush, so any other form stops here, before PHP starts.
func phpArguments(arguments []string) []string {
	var refusal string
	switch {
	case len(arguments) == 0:
		refusal = "php takes a script or -r CODE."
	case arguments[0] == "-r" && len(arguments) == 1:
		refusal = "php -r takes CODE."
	case arguments[0] == "-r" && len(arguments) > 2:
		refusal = fmt.Sprintf("php -r CODE takes no argument after CODE, and %s follows it.", arguments[2])
	case arguments[0] != "-r" && strings.HasPrefix(arguments[0], "-"):
		refusal = fmt.Sprintf("php option %s is not supported.", arguments[0])
	default:
		return append([]string{"php-cli"}, arguments...)
	}
	fmt.Fprintf(os.Stderr, "%s\n\n", refusal)
	fmt.Fprintf(os.Stderr, phpUsage+"\n", siteName())
	os.Exit(1)
	return nil
}

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
// It returns the channel the guard listens on, which stopServer feeds where a signal
// cannot reach the process.
func stopGuard() (context.Context, chan<- os.Signal) {
	signals := make(chan os.Signal, 1)
	signal.Notify(signals, os.Interrupt, syscall.SIGTERM)
	return guard(signals, shutdownDeadline, func(code int) {
		fmt.Fprintf(os.Stderr, "%s did not stop within %s. Forcing exit.\n", siteName(), shutdownDeadline)
		os.Exit(code)
	}), signals
}

// stopServer ends the server the way Ctrl+C does: Caddy traps the interrupt and stops
// gracefully, and stopGuard's signal.Notify sees the same one. Windows cannot deliver
// os.Interrupt to a process, so there the guard is armed through its channel and
// Caddy stops directly.
func stopServer(guardSignals chan<- os.Signal) {
	self, err := os.FindProcess(os.Getpid())
	if err != nil {
		panic(err)
	}
	if self.Signal(os.Interrupt) == nil {
		return
	}
	select {
	case guardSignals <- os.Interrupt:
	default:
	}
	if err := caddy.Stop(); err != nil {
		panic(err)
	}
	os.Exit(0)
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
	// The Engine executable alone has no application, and a Packaged site's
	// command line has no php word.
	if application == "" && len(os.Args) > 1 && os.Args[1] == "php" {
		os.Args = append([]string{os.Args[0]}, phpArguments(os.Args[2:])...)
		return
	}
	// launch.php replaces itself with this command to serve the site, naming the
	// fixed Caddyfile the application ships.
	if len(os.Args) == 3 && os.Args[1] == "php-server" {
		// The server waits for itself. A separate process would first extract its own copy
		// of the embedded application, which takes longer than the wait on a slow disk.
		stopping, guardSignals := stopGuard()
		go run(stopping, siteName(), plan{
			probe: strings.TrimSuffix(os.Getenv("DRUPACK_RUNTIME_URL"), "/") +
				readinessPath + url.QueryEscape(os.Getenv("DRUPACK_RUNTIME_ID")),
			open: os.Getenv("DRUPACK_RUNTIME_OPEN"),
			cron: []string{executable, "php-cli",
				filepath.Join(application, "vendor", "drush", "drush", "drush.php"), "cron"},
			dir:         application,
			stopRecord:  os.Getenv("DRUPACK_RUNTIME_STOP_RECORD"),
			poll:        500 * time.Millisecond,
			readyWithin: 2 * time.Minute,
			firstCron:   cronFirstDelay,
			cronEvery:   cronInterval,
		}, os.Stdout, os.Stderr, openBrowser, func() { stopServer(guardSignals) })
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
	// The word names what no word does, so launch.php never sees it.
	if len(os.Args) > 1 && os.Args[1] == "start" {
		os.Args = append([]string{os.Args[0], "php-cli", launchScript}, os.Args[2:]...)
		return
	}
	if len(os.Args) > 1 && os.Args[1] == "stop" {
		if err := os.Setenv("DRUPACK_RUNTIME_STOP", "1"); err != nil {
			panic(err)
		}
		os.Args = append([]string{os.Args[0], "php-cli", launchScript}, os.Args[2:]...)
		return
	}
	if len(os.Args) > 1 && nodeCommands[os.Args[1]] != "" {
		runNode(os.Args[1], os.Args[2:])
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

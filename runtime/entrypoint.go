package main

import (
	"context"
	"fmt"
	"io"
	"net/url"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"runtime/debug"
	"strings"
	"syscall"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig"
	"github.com/dunglas/frankenphp"
)

var version = "dev"

// libc is empty for a build that links no libc of its own. A Linux release
// publishes a file per libc, so a report has to say which ran.
var libc = ""

var (
	frankenphpVersion string
	sqliteVersion     string
)

// One runtime build serves every site of an engine release, so the launcher
// exports the site's name and release.
func siteName() string {
	return os.Getenv("DRUPACK_RUNTIME_NAME")
}

func release() string {
	site := siteName() + " " + os.Getenv("DRUPACK_RUNTIME_SITE_VERSION")
	if libc == "" {
		return site + " (drupack " + version + ")"
	}
	return site + " (drupack " + version + ", " + libc + ")"
}

func components() []string {
	lines := []string{
		"FrankenPHP " + frankenphpVersion,
		"PHP " + frankenphp.Version().Version,
		"Caddy " + strings.TrimPrefix(caddyVersion(), "v"),
		"SQLite " + sqliteVersion,
	}
	if site := os.Getenv("DRUPACK_RUNTIME_COMPONENTS"); site != "" {
		lines = append(lines, strings.Split(site, "\n")...)
	}
	return lines
}

// caddy.Version prefixes the CustomVersion the build scripts set.
func caddyVersion() string {
	info, _ := debug.ReadBuildInfo()
	for _, module := range info.Deps {
		if module.Path == caddy.ImportPath {
			return module.Version
		}
	}
	panic("the build information names no " + caddy.ImportPath)
}

const phpUsage = `Usage: %[1]s php SCRIPT [ARGUMENTS]
       %[1]s php -r CODE`

// php-cli reads no PHP option and passes -r code no $argv. The arguments come
// from the reader or from Drush, so any other form stops here, before PHP starts.
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

// The Caddyfile answers 204 for this site's identity token and 404 for any other,
// without reaching Drupal, so the readiness poll in watch.go renders no page.
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

// writeFromRedirectedEnd moves stdout and stderr to the end of the file each one names,
// past PHP's lines. Two handles on one file would each keep their own offset, so stderr
// writes through stdout when both name the same file. The reader's shell sets these
// handles: a missing one fails Stat, and a console or pipe one has no end to seek.
func writeFromRedirectedEnd() {
	for _, stream := range []*os.File{os.Stdout, os.Stderr} {
		if info, err := stream.Stat(); err == nil && info.Mode().IsRegular() {
			if _, err := stream.Seek(0, io.SeekEnd); err != nil {
				panic(err)
			}
		}
	}
	out, outErr := os.Stdout.Stat()
	errs, errsErr := os.Stderr.Stat()
	if outErr == nil && errsErr == nil && out.Mode().IsRegular() && os.SameFile(out, errs) {
		os.Stderr = os.Stdout
	}
}

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

// PHPRC already names the runtime directory in every hop, including this one,
// so php.ini loads from there without a scan path naming the application too.
// Caddy's run logs an adapter error into its early-start buffer after flushing it, so
// the error never prints. The adapter runs once here, without Caddy's logger, and run
// reads the adapted JSON from a pipe on its stdin. Caddy names the line that imports
// a project's drupack.caddy when the error sits inside it, so the file's path, when
// the start passes one, follows the error.
func serveCaddyfile(config, projectRules string) {
	body, err := os.ReadFile(config)
	if err != nil {
		panic(err)
	}
	adapted, _, err := caddyconfig.GetAdapter("caddyfile").Adapt(body, map[string]any{"filename": config})
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		if projectRules != "" {
			fmt.Fprintf(os.Stderr, "The project's %s is part of this configuration.\n", projectRules)
		}
		os.Exit(1)
	}
	reader, writer, err := os.Pipe()
	if err != nil {
		panic(err)
	}
	// The pipe holds less than the JSON, so a goroutine feeds it while run reads.
	go func() {
		_, _ = writer.Write(adapted)
		_ = writer.Close()
	}()
	os.Stdin = reader
	os.Args = []string{os.Args[0], "run", "--config", "-"}
}

// This file builds inside frankenphp's own module, which cannot import the
// launcher's internal/runtime package, so the canonical form is restated here.
func canonical(path string) string {
	return filepath.ToSlash(path)
}

func init() {
	executable, err := os.Executable()
	if err != nil {
		panic(err)
	}
	// PHP, Caddy and the reader's terminal read this variable.
	if err := os.Setenv("DRUPACK_RUNTIME_BINARY", canonical(executable)); err != nil {
		panic(err)
	}
	// The launcher unpacks the application once per release and names its directory.
	application := os.Getenv("DRUPACK_RUNTIME_APP_DIR")
	if application == "" {
		application = frankenphp.EmbeddedAppPath
	}
	// The php word runs a script from the reader's directory on both executables, so it
	// goes before the change into the application.
	if len(os.Args) > 1 && os.Args[1] == "php" {
		os.Args = append([]string{os.Args[0]}, phpArguments(os.Args[2:])...)
		return
	}
	// A build step runs this binary on its own to read its version, with no
	// launcher to name a directory and no embedded application to fall back to.
	if application != "" {
		if err := os.Chdir(application); err != nil {
			panic(err)
		}
	}
	if (len(os.Args) == 3 || len(os.Args) == 4) && os.Args[1] == "php-server" {
		// A Windows server redirected to a file writes from the file's end.
		if runtime.GOOS == "windows" {
			writeFromRedirectedEnd()
		}
		// The server waits for itself. A separate process would first extract its own copy
		// of the embedded application, which takes longer than the wait on a slow disk.
		stopping, guardSignals := stopGuard()
		// The engine executable has no application and runs no cron.
		var cron []string
		if application != "" {
			cron = []string{executable, "php-cli",
				filepath.Join(application, "vendor", "drush", "drush", "drush.php"), "cron"}
		}
		roots, err := rootPool(os.Getenv("DRUPACK_RUNTIME_TLS_ROOT"))
		if err != nil {
			panic(err)
		}
		go run(stopping, siteName(), plan{
			probe: strings.TrimSuffix(os.Getenv("DRUPACK_RUNTIME_URL"), "/") +
				readinessPath + url.QueryEscape(os.Getenv("DRUPACK_RUNTIME_ID")),
			roots:       roots,
			open:        os.Getenv("DRUPACK_RUNTIME_OPEN"),
			cron:        cron,
			dir:         application,
			stopRecord:  os.Getenv("DRUPACK_RUNTIME_STOP_RECORD"),
			poll:        500 * time.Millisecond,
			readyWithin: 2 * time.Minute,
			firstCron:   cronFirstDelay,
			cronEvery:   cronInterval,
		}, os.Stdout, os.Stderr, openBrowser, func() { stopServer(guardSignals) })
		projectRules := ""
		if len(os.Args) == 4 {
			projectRules = os.Args[3]
		}
		serveCaddyfile(os.Args[2], projectRules)
		return
	}
	// launch.php replaces itself with this command when its Site data is already served,
	// since a handover starts no server to open the browser from. The target is a working
	// credential, so it arrives in the environment, which only this user can read.
	if len(os.Args) > 1 && os.Args[1] == "browser-open" {
		openBrowser(os.Getenv("DRUPACK_RUNTIME_OPEN"))
		os.Exit(0)
	}
	if len(os.Args) == 2 && (os.Args[1] == "--version" || os.Args[1] == "-v") {
		fmt.Println(release())
		fmt.Println(strings.Join(components(), "\n"))
		os.Exit(0)
	}
	// FrankenPHP's own command line takes every other line. The launcher sends the
	// reader's words to launch.php or serve.php through its php-cli.
}

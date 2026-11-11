package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"runtime/debug"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig"
	caddycmd "github.com/caddyserver/caddy/v2/cmd"
	"github.com/caddyserver/caddy/v2/modules/caddytls"
	"github.com/dunglas/frankenphp"
	"github.com/spf13/cobra"
	"go.uber.org/zap/exp/zapslog"
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

func components() ([]string, error) {
	caddyRelease, err := caddyVersion()
	if err != nil {
		return nil, err
	}
	lines := []string{
		"FrankenPHP " + frankenphpVersion,
		"PHP " + frankenphp.Version().Version,
		"Caddy " + strings.TrimPrefix(caddyRelease, "v"),
		"SQLite " + sqliteVersion,
	}
	if site := os.Getenv("DRUPACK_RUNTIME_COMPONENTS"); site != "" {
		lines = append(lines, strings.Split(site, "\n")...)
	}
	return lines, nil
}

// caddy.Version prefixes the CustomVersion the build scripts set.
func caddyVersion() (string, error) {
	info, _ := debug.ReadBuildInfo()
	for _, module := range info.Deps {
		if module.Path == caddy.ImportPath {
			return module.Version, nil
		}
	}
	return "", errors.New("the build information names no " + caddy.ImportPath)
}

// printVersion prints what the launcher shows for --version: the release line,
// then one line per component.
func printVersion() error {
	lines, err := components()
	if err != nil {
		return err
	}
	fmt.Println(release())
	fmt.Println(strings.Join(lines, "\n"))
	return nil
}

const phpUsage = `Usage: %[1]s php SCRIPT [ARGUMENTS]
       %[1]s php -r CODE`

// phpArguments refuses a line php-cli would misread: it reads no PHP option and
// passes -r code no $argv. The arguments come from the reader or from Drush, so
// any other form stops here, before PHP starts.
func phpArguments(arguments []string) error {
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
		return nil
	}
	return fmt.Errorf("%s\n\n%s", refusal, fmt.Sprintf(phpUsage, siteName()))
}

// php runs a script or -r CODE from the reader's directory, as FrankenPHP's own
// php-cli command does, and exits with PHP's status.
func php(arguments []string) error {
	if err := phpArguments(arguments); err != nil {
		return err
	}
	if err := os.Chdir(origin); err != nil {
		return err
	}
	// PHP's command line reads its first argument as the program's name.
	line := append([]string{os.Args[0]}, arguments...)
	logger := slog.New(zapslog.NewHandler(caddy.Log().Core(), zapslog.WithName("frankenphp")))
	os.Exit(frankenphp.ExecuteScriptCLI(line[0], line, frankenphp.WithLogger(logger)))
	return nil
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

// closeInherited marks every descriptor past stderr close-on-exec. The launcher
// hands the server the Serving lease without that flag, and a browser the
// server starts would otherwise hold the lease after the server exits.
// syscall.CloseOnExec takes an int on Unix and a Handle on Windows, which
// never calls this.
func closeInherited[F ~int | ~uintptr](closeOnExec func(F)) error {
	entries, err := os.ReadDir("/dev/fd")
	if err != nil {
		return err
	}
	for _, entry := range entries {
		if fd, _ := strconv.Atoi(entry.Name()); fd > 2 {
			closeOnExec(F(fd))
		}
	}
	return nil
}

// A healthy stop takes about three seconds.
const shutdownDeadline = 10 * time.Second

// writeFromRedirectedEnd moves stdout and stderr to the end of the file each one names,
// past PHP's lines. Two handles on one file would each keep their own offset, so stderr
// writes through stdout when both name the same file. The reader's shell sets these
// handles: a missing one fails Stat, and a console or pipe one has no end to seek.
func writeFromRedirectedEnd() error {
	for _, stream := range []*os.File{os.Stdout, os.Stderr} {
		if info, err := stream.Stat(); err == nil && info.Mode().IsRegular() {
			if _, err := stream.Seek(0, io.SeekEnd); err != nil {
				return err
			}
		}
	}
	out, outErr := os.Stdout.Stat()
	errs, errsErr := os.Stderr.Stat()
	if outErr == nil && errsErr == nil && out.Mode().IsRegular() && os.SameFile(out, errs) {
		os.Stderr = os.Stdout
	}
	return nil
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
// It runs in the stop channel's request, where no caller takes an error, so a
// failure prints and exits with 1.
func stopServer(guardSignals chan<- os.Signal) {
	self, err := os.FindProcess(os.Getpid())
	if err == nil && self.Signal(os.Interrupt) == nil {
		return
	}
	select {
	case guardSignals <- os.Interrupt:
	default:
	}
	if err := caddy.Stop(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	os.Exit(0)
}

// cronInterval is the period automated_cron ships with, whose in-request run the
// packaged settings turn off. cronFirstDelay holds the first run back, so a
// reader's opening pages, which compile the container and render uncached, do
// not share the database with it.
const cronInterval = 3 * time.Hour
const cronFirstDelay = 2 * time.Minute

// certificateWait bounds the wait for Caddy's own certificates. A start past it
// serves, and Caddy keeps issuing.
const certificateWait = 5 * time.Second

// issuedNames lists the names Caddy's internal issuer signs for in an adapted
// configuration.
func issuedNames(adapted []byte) ([]string, error) {
	var config struct {
		Apps struct {
			TLS struct {
				Automation struct {
					Policies []struct {
						Subjects []string
						Issuers  []struct{ Module string }
					}
				}
			}
		}
	}
	if err := json.Unmarshal(adapted, &config); err != nil {
		return nil, err
	}
	var names []string
	for _, policy := range config.Apps.TLS.Automation.Policies {
		for _, issuer := range policy.Issuers {
			if issuer.Module == "internal" {
				names = append(names, policy.Subjects...)
			}
		}
	}
	return names, nil
}

// certified reports whether Caddy holds an unexpired certificate for every name.
func certified(names []string) bool {
	for _, name := range names {
		valid := false
		for _, certificate := range caddytls.AllMatchingCertificates(name) {
			valid = valid || !certificate.Expired()
		}
		if !valid {
			return false
		}
	}
	return true
}

// awaitCertificates returns once Caddy holds a certificate for every name. Caddy
// issues after the load, and a handshake before that fails.
func awaitCertificates(names []string) {
	for deadline := time.Now().Add(certificateWait); !certified(names) && time.Now().Before(deadline); {
		time.Sleep(5 * time.Millisecond)
	}
}

// adapt returns the JSON of the Caddyfile at config. Caddy names the line that
// imports a project's file when the error sits inside it, so the path of each
// project file the start passes follows the error.
func adapt(config string, projectFiles []string) ([]byte, error) {
	body, err := os.ReadFile(config)
	if err != nil {
		return nil, err
	}
	adapted, _, err := caddyconfig.GetAdapter("caddyfile").Adapt(body, map[string]any{"filename": config})
	if err != nil {
		for _, file := range projectFiles {
			err = fmt.Errorf("%w\nThe project's %s is part of this configuration.", err, file)
		}
	}
	return adapted, err
}

// serve loads the Caddyfile at arguments[0], then runs the stop channel and cron
// beside the server until the process exits. PHPRC already names the runtime
// directory in every hop, including this one, so php.ini loads from there
// without a scan path naming the application too. The server waits for itself:
// a separate process would first extract its own copy of the embedded
// application, which takes longer than the wait on a slow disk.
func serve(arguments []string) error {
	caddy.TrapSignals()
	stopping, guardSignals := stopGuard()
	// A Windows server redirected to a file writes from the file's end.
	var err error
	if runtime.GOOS == "windows" {
		err = writeFromRedirectedEnd()
	} else {
		err = closeInherited(syscall.CloseOnExec)
	}
	if err != nil {
		return err
	}
	adapted, err := adapt(arguments[0], arguments[1:])
	if err != nil {
		return err
	}
	if err := caddy.Load(adapted, true); err != nil {
		return err
	}
	names, err := issuedNames(adapted)
	if err != nil {
		return err
	}
	awaitCertificates(names)
	// The engine executable has no application and runs no cron.
	var cron []string
	if application != "" {
		cron = []string{executable, "php-cli",
			filepath.Join(application, "vendor", "drush", "drush", "drush.php"), "cron"}
	}
	err = run(stopping, siteName(), plan{
		open:       os.Getenv("DRUPACK_RUNTIME_OPEN"),
		cron:       cron,
		dir:        application,
		stopRecord: os.Getenv("DRUPACK_RUNTIME_STOP_RECORD"),
		firstCron:  cronFirstDelay,
		cronEvery:  cronInterval,
	}, os.Stdout, os.Stderr, openBrowser, func() { stopServer(guardSignals) })
	if err != nil {
		// A site nothing can stop does not serve.
		return errors.Join(err, caddy.Stop())
	}
	// Caddy ends the process once it has stopped.
	select {}
}

// This file builds inside frankenphp's own module, which cannot import the
// launcher's internal/runtime package, so the canonical form is restated here.
func canonical(path string) string {
	return filepath.ToSlash(path)
}

// executable is this file's path, origin the directory the process started in,
// and application the directory of the Drupal application, "" for a build step
// that runs the binary on its own. init sets all three.
var executable, origin, application string

// register adds a command that takes its words unparsed and prints a failure
// as Caddy's command line prints one.
func register(name, usage, short string, arguments cobra.PositionalArgs, handler func([]string) error) {
	caddycmd.RegisterCommand(caddycmd.Command{
		Name: name, Usage: usage, Short: short,
		CobraFunc: func(cmd *cobra.Command) {
			cmd.DisableFlagParsing = true
			cmd.Args = arguments
			cmd.RunE = func(_ *cobra.Command, words []string) error { return handler(words) }
		},
	})
}

func init() {
	var err error
	if executable, err = os.Executable(); err != nil {
		panic(err)
	}
	// PHP, Caddy and the reader's terminal read this variable.
	if err := os.Setenv("DRUPACK_RUNTIME_BINARY", canonical(executable)); err != nil {
		panic(err)
	}
	if origin, err = os.Getwd(); err != nil {
		panic(err)
	}
	// The launcher unpacks the application once per release and names its directory.
	application = os.Getenv("DRUPACK_RUNTIME_APP_DIR")
	if application == "" {
		application = frankenphp.EmbeddedAppPath
	}
	// FrankenPHP's own php-cli, which the launcher and cron call, runs from the
	// application. A build step runs this binary on its own, with no launcher to
	// name a directory and no embedded application to fall back to.
	if application != "" {
		if err := os.Chdir(application); err != nil {
			panic(err)
		}
	}
	register("drupack-serve", "CADDYFILE [PROJECT_FILE...]", "Serves the site of a Caddyfile the launcher rendered",
		cobra.RangeArgs(1, 3), serve)
	register("php", "SCRIPT [ARGUMENTS] | -r CODE", "Runs a script or -r CODE on the bundled PHP, with no PHP option",
		cobra.ArbitraryArgs, php)
	register("drupack-version", "", "Prints the release and the release of each component",
		cobra.NoArgs, func([]string) error { return printVersion() })
	// FrankenPHP's own command line takes every other line.
}

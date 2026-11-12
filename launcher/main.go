// Command launcher unpacks its embedded runtime into the user's cache and
// runs it. launcher/cmd/pack builds one launcher per runtime build.
package main

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/tls"
)

// consoleWord is the leading word that stands in for a file manager's console on a
// platform whose console no file manager creates. The conformance suite gives it.
const consoleWord = "--console-owned"

// takeConsole strips consoleWord from args and reports whether it led them.
func takeConsole(args []string) ([]string, bool) {
	if len(args) > 1 && args[1] == consoleWord {
		return append([]string{args[0]}, args[2:]...), true
	}
	return args, false
}

func main() {
	args, named := takeConsole(os.Args)
	args, child := takeChild(args)
	if err := run(args, child, named); err != nil {
		code := 1
		if exit, ok := errors.AsType[exitError](err); ok {
			code = int(exit)
		} else if !errors.Is(err, errReported) {
			fmt.Fprintln(os.Stderr, err)
		}
		holdConsole(child != nil)
		os.Exit(code)
	}
}

// exitError is the code of a child that printed its own failure, which this
// process exits with.
type exitError int

func (c exitError) Error() string {
	return fmt.Sprintf("exit status %d", int(c))
}

// run answers args, the reader's line without the console and child words. It
// builds the one Launch every other function reads: the packed variables and
// the process's own state first, the unpacked release once a word needs it.
func run(args []string, child *detachedChild, consoleNamed bool) error {
	l := Launch{
		// args, not the resolved executable path, keeps argv[0] the path the reader invoked.
		Args:         args,
		Parent:       os.Environ(),
		AppChecksum:  string(appChecksum),
		Name:         siteName,
		Version:      siteVersion,
		Components:   siteComponents,
		Engine:       engine,
		Musl:         musl,
		Child:        child,
		ConsoleOwned: consoleOwned(child != nil) || consoleNamed,
		FoldCase:     foldCase,
	}
	var err error
	if l.TLSDir, err = tlsDir(l); err != nil {
		return err
	}
	// A refused line is answered below, after the update notice.
	c, refusal := parse(l)
	// trust may ask for a password.
	if c.word == "trust" {
		return tls.Trust(os.Stdout, l.TLSDir, c.words)
	}
	launcher, err := os.Executable()
	if err != nil {
		return err
	}
	// A self-update renames the file a symlink on PATH names, so the old file it
	// moves aside sits beside that one.
	executable := launcher
	if l.Engine {
		if executable, err = filepath.EvalSymlinks(launcher); err != nil {
			return err
		}
		removeReplaced(executable)
	}
	if c.word == "self-update" {
		return selfUpdate(l, c.words, releaseURL, executable, os.Stdout)
	}
	if l.Root, err = runtime.Root(l.Name, os.Stderr); err != nil {
		return err
	}
	cache := runtime.NewCache(l.Root, runtime.HoldUsage)
	if c.word == "clean" {
		dry, err := cleanArguments(c.words)
		if err != nil {
			return err
		}
		return cache.Clean(l.Name, dry, os.Stdout, os.Stderr)
	}
	if l.Engine {
		updateNotice(l, executable, os.Stderr, runtime.Terminal(os.Stderr), time.Now(), func() error { return startCheck(launcher) })
	}
	if refusal != nil {
		return refusal
	}
	// A stop unpacks nothing.
	if c.word == "stop" {
		return runStop(l, c, os.Stdout)
	}
	m, err := runtime.ParseManifest(runtimeManifest)
	if err != nil {
		return err
	}
	directory, application, err := cache.PrepareRelease(runtime.Release{
		Payload:     runtimePayload,
		Manifest:    m,
		AppChecksum: l.AppChecksum,
		AppPayload:  appPayload,
	}, os.Stderr)
	if err != nil {
		return err
	}
	l.Entry, l.Application = filepath.Join(directory, m.Entry), application
	if l.NodeBin, err = prepareNode(cache); err != nil {
		return err
	}
	switch {
	case c.word == "help":
		fmt.Println(helpText(l))
		return nil
	case c.word == "node" && l.NodeBin == "":
		return nodeRefusal(l, args[1])
	case c.word == "start":
		return start(l, c)
	case c.word == "drush", c.word == "export", l.Engine && c.word == "dr":
		return runInFolder(l, c)
	}
	return launch(Plan(l))
}

// runInFolder runs drush, export, or the engine's dr, from the reader's directory.
func runInFolder(l Launch, c command) error {
	cwd, err := os.Getwd()
	if err != nil {
		return err
	}
	f := Facts{Launch: l}
	var call Command
	switch {
	case c.word == "drush" && l.Engine:
		call, err = engineDrush(f, c, cwd)
	case c.word == "drush":
		call, err = siteDrush(f, c, cwd)
	case c.word == "dr":
		call, err = engineCore(f, c, cwd)
	default:
		var export exportCall
		plan := siteExport
		if l.Engine {
			plan = engineExport
		}
		export, err = plan(f, c, cwd)
		if err == nil {
			err = export.requireTomeStatic(l.Name)
		}
		call = export.run
	}
	if err != nil {
		return err
	}
	return launch(call)
}

// nodeRefusal answers a Node word on a file that carries no Node. A site whose
// contract names Node was packed without it for this libc.
func nodeRefusal(l Launch, word string) error {
	site, err := siteconfig.Load(l.Application)
	if err != nil {
		return err
	}
	if site.Node == "" {
		return fmt.Errorf("Unknown command: %s\n\n%s", word, siteHelpText(l.Name))
	}
	return fmt.Errorf("%s: this musl build of %s carries no Node. Run the glibc build, the file without -musl in its name.", word, l.Name)
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

// tlsDir is the folder holding the user's local root: a caller's DRUPACK_TLS_DIR,
// else the system's. The runtime runs from the application directory, so a
// relative value is made absolute against the reader's directory here. A caller
// who sets it empty names no folder.
func tlsDir(l Launch) (string, error) {
	if dir, set := l.lookup("DRUPACK_TLS_DIR"); set {
		if dir == "" {
			return "", nil
		}
		return filepath.Abs(dir)
	}
	return runtime.TLSDir(), nil
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

package main

import (
	"fmt"
	"os"
	"path/filepath"
	"slices"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// restart stops the background server of c's target and starts it again, in
// the background, from the line its start record keeps. The words resolve from
// the recorded working directory, and the DRUPACK_ inputs come from this
// process's environment. With no server running it starts from the record.
func restart(l Launch, c command) error {
	dir, what, named, found, err := serverDir(l, c)
	if err != nil {
		return err
	}
	record, recorded := state.Start{}, false
	if found {
		if record, recorded, err = dir.Start(); err != nil {
			return err
		}
	}
	switch {
	case !recorded:
		return fmt.Errorf("No start of this %s is recorded: %s. Start it with %s start.", what, named, l.Name)
	case !record.Background:
		return fmt.Errorf("%s started this %s in the foreground: %s. Restart it where it runs, in its terminal or its service manager.", l.Name, what, named)
	}
	replayed, line, err := replay(l, record, named)
	if err != nil {
		return err
	}
	// The directory is entered before the stop, so a removed one leaves the server serving.
	if err := os.Chdir(record.Cwd); err != nil {
		return fmt.Errorf("Cannot enter the directory the start ran in: %s", record.Cwd)
	}
	if err := stop(l.Name, dir, what, named, stopWait, os.Stdout); err != nil {
		return err
	}
	return start(replayed, line)
}

// replay is the Launch and command of the recorded start. argv0 is made
// absolute against this directory. The engine's target becomes the absolute
// folder and a site's --data-dir goes last, so the stop command the start
// prints works from any directory.
func replay(l Launch, record state.Start, named string) (Launch, command, error) {
	argv0 := l.Args[0]
	// A bare name is found on PATH from any directory.
	if filepath.Base(argv0) != argv0 && !filepath.IsAbs(argv0) {
		absolute, err := filepath.Abs(argv0)
		if err != nil {
			return Launch{}, command{}, err
		}
		argv0 = absolute
	}
	// named is canonical, with forward slashes, and the printed stop names it in the platform's form.
	named = filepath.FromSlash(named)
	words := slices.Clone(record.Words)
	if !l.Engine {
		words = append(withoutOptions(words, stopOptions, func(option) bool { return true }), "--data-dir="+named)
	}
	l.Args = append([]string{argv0}, words...)
	// A console-owned start serves in the foreground.
	l.ConsoleOwned = false
	c, err := parse(l)
	if err != nil {
		return Launch{}, command{}, err
	}
	if l.Engine {
		c.target = named
	}
	return l, c, nil
}

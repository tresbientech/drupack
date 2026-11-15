package main

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/fsguard"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// stopWait bounds the wait for the lease to free. runtime/frankenphp/entrypoint.go forces
// the server's own exit at 10 seconds.
const stopWait = 15 * time.Second

// runStop stops the server of c's target: the engine's folder, whose state
// lives in the cache under l.Root, or a site's Site data. The stop creates
// nothing in a Site data path that does not resolve.
func runStop(l Launch, c command, out io.Writer) error {
	dir, what, named, found, err := serverDir(l, c)
	switch {
	case err != nil:
		return err
	case !found:
		return notRunning(l.Name, out)
	}
	return stop(l.Name, dir, what, named, stopWait, out)
}

// serverDir is the state directory of c's target, with what and named
// describing the target in messages. found is false for a Site data path that
// does not resolve, which holds no site, and named is then the path as written.
func serverDir(l Launch, c command) (dir state.Dir, what, named string, found bool, err error) {
	if l.Engine {
		project, err := projectFolder(l.Invoked, c.target)
		if err != nil {
			return state.Dir{}, "", "", false, err
		}
		return state.FolderEntry(l.Root, project, l.FoldCase), "folder", project, true, nil
	}
	// The path is made absolute before links resolve, so `link/..` names the link's parent.
	data, err := filepath.Abs(c.target)
	if err == nil {
		data, err = filepath.EvalSymlinks(data)
	}
	if err != nil {
		return state.Dir{}, "Site data", c.target, false, nil
	}
	data = fsguard.Canonical(data)
	return state.At(data), "Site data", data, true, nil
}

// projectFolder resolves links first, then makes the path absolute. The folder's
// cache entry is keyed by this path.
func projectFolder(invoked, written string) (string, error) {
	missing := fmt.Errorf("No such directory: %s. Run %s --help.", written, invoked)
	resolved, err := filepath.EvalSymlinks(written)
	if err != nil {
		return "", missing
	}
	if resolved, err = filepath.Abs(resolved); err != nil {
		return "", err
	}
	if info, err := os.Stat(resolved); err != nil || !info.IsDir() {
		return "", missing
	}
	return fsguard.Canonical(resolved), nil
}

// stop ends the server through the channel its stop record names, then waits
// for the lease to free. A free lease means no server runs, whatever a stale
// record says. name is the executable's, and what and named describe the
// target in messages.
func stop(name string, dir state.Dir, what, named string, wait time.Duration, out io.Writer) error {
	held, err := dir.Lease()
	if err != nil {
		return err
	}
	if !held {
		return notRunning(name, out)
	}
	channel, err := dir.StopChannel()
	if errors.Is(err, fs.ErrNotExist) {
		return fmt.Errorf("A %s start is still preparing this %s: %s", name, what, named)
	}
	if err != nil {
		return err
	}
	if refusal := channel.Stop(); refusal != "" {
		return fmt.Errorf("The stop channel of %s refused the request (%s): %s", name, refusal, named)
	}
	free, err := dir.WaitFree(wait)
	if err != nil {
		return err
	}
	if !free {
		return fmt.Errorf("%s did not stop within %d seconds: %s", name, int(wait.Seconds()), named)
	}
	fmt.Fprintf(out, "%s stopped.\n", name)
	return nil
}

func notRunning(name string, out io.Writer) error {
	fmt.Fprintf(out, "%s is not running.\n", name)
	return nil
}

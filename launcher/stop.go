package main

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// stopWait bounds the wait for the lease to free. runtime/entrypoint.go forces
// the server's own exit at 10 seconds.
const stopWait = 15 * time.Second

// runStop stops the server of c's target: the engine's folder, whose state
// lives in the cache under l.Root, or a site's Site data. A Site data path that
// does not resolve holds no site, and the stop creates nothing there.
func runStop(l Launch, c command, out io.Writer) error {
	if l.Engine {
		project, err := projectFolder(l.Name, c.target)
		if err != nil {
			return err
		}
		return stop(l.Name, state.FolderEntry(l.Root, project, l.FoldCase), "folder", project, stopWait, out)
	}
	// The path is made absolute before links resolve, so `link/..` names the link's parent.
	data, err := filepath.Abs(c.target)
	if err == nil {
		data, err = filepath.EvalSymlinks(data)
	}
	if err != nil {
		return notRunning(l.Name, out)
	}
	data = runtime.Canonical(data)
	return stop(l.Name, state.At(data), "Site data", data, stopWait, out)
}

// projectFolder resolves links first, then makes the path absolute. The folder's
// cache entry is keyed by this path, which must match the previous release's.
func projectFolder(name, written string) (string, error) {
	missing := fmt.Errorf("No such directory: %s. Run %s --help.", written, name)
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
	return runtime.Canonical(resolved), nil
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

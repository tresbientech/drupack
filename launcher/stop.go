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
// lives in the cache under root, or a site's Site data. A Site data path that
// does not resolve holds no site, and the stop creates nothing there.
func runStop(c command, root string, out io.Writer) error {
	if engine {
		project, err := projectFolder(c.target)
		if err != nil {
			return err
		}
		return stop(state.FolderEntry(root, project, foldCase), "folder", project, stopWait, out)
	}
	// launch.php made the path absolute before it resolved links, so `link/..` names the link's parent.
	data, err := filepath.Abs(c.target)
	if err == nil {
		data, err = filepath.EvalSymlinks(data)
	}
	if err != nil {
		return notRunning(out)
	}
	data = runtime.Canonical(data)
	return stop(state.At(data), "Site data", data, stopWait, out)
}

// projectFolder resolves links before it makes the path absolute, as serve.php's realpath did.
func projectFolder(written string) (string, error) {
	missing := fmt.Errorf("No such directory: %s. Run %s --help.", written, siteName)
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
// record says. what and named describe the target in messages.
func stop(dir state.Dir, what, named string, wait time.Duration, out io.Writer) error {
	held, err := dir.Lease()
	if err != nil {
		return err
	}
	if !held {
		return notRunning(out)
	}
	channel, err := dir.StopChannel()
	if errors.Is(err, fs.ErrNotExist) {
		return fmt.Errorf("A %s start is still preparing this %s: %s", siteName, what, named)
	}
	if err != nil {
		return err
	}
	if refusal := channel.Stop(); refusal != "" {
		return fmt.Errorf("The stop channel of %s refused the request (%s): %s", siteName, refusal, named)
	}
	free, err := dir.WaitFree(wait)
	if err != nil {
		return err
	}
	if !free {
		return fmt.Errorf("%s did not stop within %d seconds: %s", siteName, int(wait.Seconds()), named)
	}
	fmt.Fprintf(out, "%s stopped.\n", siteName)
	return nil
}

func notRunning(out io.Writer) error {
	fmt.Fprintf(out, "%s is not running.\n", siteName)
	return nil
}

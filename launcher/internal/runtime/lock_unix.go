//go:build unix

package runtime

import (
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"syscall"
	"time"
)

// HoldUsage marks entry as running for the rest of this process's life, so
// cleanup skips it. The descriptor stays open on purpose, and syscall.Open
// sets no close-on-exec flag, so the lock survives the exec into the runtime
// and the kernel drops it when the server exits or is killed. A marker that
// cannot be held is an error: an unmarked entry can be removed under the run.
func HoldUsage(entry string) error {
	descriptor, err := syscall.Open(filepath.Join(entry, usageName), syscall.O_CREAT|syscall.O_RDWR, 0600)
	if err != nil {
		return err
	}
	// entryInUse takes the exclusive lock for an instant, which refuses this
	// shared one, so that error retries.
	for range holdAttempts {
		err = syscall.Flock(descriptor, syscall.LOCK_SH|syscall.LOCK_NB)
		if err != syscall.EWOULDBLOCK {
			break
		}
		time.Sleep(holdRetryInterval)
	}
	if err != nil {
		syscall.Close(descriptor)
	}
	return err
}

// entryInUse reports whether another process still runs from entry. A missing
// marker means no process has held it since the directory was written.
func entryInUse(entry string) bool {
	descriptor, err := syscall.Open(filepath.Join(entry, usageName), syscall.O_RDWR, 0600)
	if err != nil {
		return false
	}
	defer syscall.Close(descriptor)
	return syscall.Flock(descriptor, syscall.LOCK_EX|syscall.LOCK_NB) != nil
}

// lockRoot serializes staging across every process sharing root. Blocking is
// correct here: the kernel releases a dead holder's lock.
func lockRoot(root string) (func(), error) {
	file, err := os.OpenFile(filepath.Join(root, lockName), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return nil, err
	}
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX); err != nil {
		file.Close()
		return nil, err
	}
	return func() {
		syscall.Flock(int(file.Fd()), syscall.LOCK_UN)
		file.Close()
	}, nil
}

// leaseHeld reports whether a server holds the Serving lease in entry, a
// folder's state. serve.php takes it with PHP's flock, which is flock(2) here.
// A missing lease means no server ever ran from the entry. A lease this
// process cannot open counts as held.
func leaseHeld(entry string) bool {
	file, err := os.OpenFile(filepath.Join(entry, leaseName), os.O_RDWR, 0)
	if err != nil {
		return !errors.Is(err, fs.ErrNotExist)
	}
	defer file.Close()
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		return true
	}
	syscall.Flock(int(file.Fd()), syscall.LOCK_UN)
	return false
}

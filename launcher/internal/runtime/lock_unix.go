//go:build unix

package runtime

import (
	"os"
	"path/filepath"
	"syscall"
)

// HoldUsage marks entry as running for the rest of this process's life, so
// cleanup skips it. The descriptor stays open on purpose, and syscall.Open
// sets no close-on-exec flag, so the lock survives the exec into the runtime
// and the kernel drops it when the server exits or is killed. A cache that
// refuses the marker still runs: the marker answers a cleanup question, and
// nothing else reads it.
func HoldUsage(entry string) {
	descriptor, err := syscall.Open(filepath.Join(entry, usageName), syscall.O_CREAT|syscall.O_RDWR, 0600)
	if err != nil {
		return
	}
	if err := syscall.Flock(descriptor, syscall.LOCK_SH|syscall.LOCK_NB); err != nil {
		syscall.Close(descriptor)
	}
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

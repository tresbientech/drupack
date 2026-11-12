//go:build unix

package runtime

import (
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
	descriptor, err := syscall.Open(filepath.Join(entry, usageName), syscall.O_CREAT|syscall.O_RDWR, 0o600)
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
	descriptor, err := syscall.Open(filepath.Join(entry, usageName), syscall.O_RDWR, 0o600)
	if err != nil {
		return false
	}
	defer syscall.Close(descriptor)
	return syscall.Flock(descriptor, syscall.LOCK_EX|syscall.LOCK_NB) != nil
}

// tryLock takes flock(2) on path without waiting. busy reports that another
// open file holds it.
func tryLock(path string) (release func(), busy bool, err error) {
	file, err := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return nil, false, err
	}
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		file.Close()
		if err == syscall.EWOULDBLOCK {
			return nil, true, nil
		}
		return nil, false, err
	}
	return func() {
		_ = syscall.Flock(int(file.Fd()), syscall.LOCK_UN)
		file.Close()
	}, false, nil
}

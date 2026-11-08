//go:build windows

package runtime

import (
	"os"
	"path/filepath"
	"syscall"
	"time"
)

// lockTimeout bounds how long a launch waits for another process's lock
// before it reports that it could not lock the cache.
const lockTimeout = 60 * time.Second

// lockPollInterval paces the retry loop: Windows has no blocking wait for a
// file handle the way flock offers on unix.
const lockPollInterval = 100 * time.Millisecond

// errorSharingViolation is ERROR_SHARING_VIOLATION, which package syscall omits.
const errorSharingViolation syscall.Errno = 32

// HoldUsage marks entry as running for the rest of this process's life, so
// cleanup skips it. The handle stays open on purpose, and Windows closes it
// when the process ends. launch_windows.go waits for the runtime it starts, so
// this process outlives the server it holds the marker for. A marker that
// cannot be held is an error: an unmarked entry can be removed under the run.
func HoldUsage(entry string) error {
	pointer, err := syscall.UTF16PtrFromString(filepath.Join(entry, usageName))
	if err != nil {
		return err
	}
	// FILE_SHARE_READ lets entryInUse open the same marker to test it, while a
	// removal of the directory holding it still fails. entryInUse opens the
	// marker exclusively for an instant, which refuses this open with a sharing
	// violation, so that error retries.
	for range holdAttempts {
		_, err = syscall.CreateFile(pointer, syscall.GENERIC_READ, syscall.FILE_SHARE_READ, nil, syscall.OPEN_ALWAYS, syscall.FILE_ATTRIBUTE_NORMAL, 0)
		if err != errorSharingViolation {
			return err
		}
		time.Sleep(holdRetryInterval)
	}
	return err
}

// entryInUse reports whether another process still runs from entry. A missing
// marker means no process has held it since the directory was written.
func entryInUse(entry string) bool {
	pointer, err := syscall.UTF16PtrFromString(filepath.Join(entry, usageName))
	if err != nil {
		return false
	}
	handle, err := syscall.CreateFile(pointer, syscall.GENERIC_READ, 0, nil, syscall.OPEN_EXISTING, syscall.FILE_ATTRIBUTE_NORMAL, 0)
	if err != nil {
		return !os.IsNotExist(err)
	}
	syscall.CloseHandle(handle)
	return false
}

// lockRoot serializes staging across every process sharing root. Windows has
// no flock, so this holds an exclusive file handle instead, retrying until
// lockTimeout. The OS releases the handle when the process dies, so a crash
// leaves no stale lock.
func lockRoot(root string) (func(), error) {
	pointer, err := syscall.UTF16PtrFromString(filepath.Join(root, lockName))
	if err != nil {
		return nil, err
	}
	deadline := time.Now().Add(lockTimeout)
	for {
		handle, err := syscall.CreateFile(pointer, syscall.GENERIC_READ|syscall.GENERIC_WRITE, 0, nil, syscall.OPEN_ALWAYS, syscall.FILE_ATTRIBUTE_NORMAL, 0)
		if err == nil {
			return func() { syscall.CloseHandle(handle) }, nil
		}
		if time.Now().After(deadline) {
			return nil, err
		}
		time.Sleep(lockPollInterval)
	}
}

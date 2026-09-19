//go:build windows

package runtime

import (
	"path/filepath"
	"syscall"
	"time"
)

// lockTimeout bounds how long a launch waits for another process's lock
// before giving up on a fresh stage and running the cache's active entry.
const lockTimeout = 60 * time.Second

// lockPollInterval paces the retry loop: Windows has no blocking wait for a
// file handle the way flock offers on unix.
const lockPollInterval = 100 * time.Millisecond

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

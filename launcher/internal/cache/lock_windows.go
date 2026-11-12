//go:build windows

package cache

import (
	"os"
	"path/filepath"
	"syscall"
	"time"

	"golang.org/x/sys/windows"
)

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
		if err != windows.ERROR_SHARING_VIOLATION {
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

// tryLock opens path with an exclusive handle, since Windows has no flock.
// busy reports that another handle holds it. The system closes the handle
// when the process dies, so a crash leaves no stale lock.
func tryLock(path string) (release func(), busy bool, err error) {
	pointer, err := syscall.UTF16PtrFromString(path)
	if err != nil {
		return nil, false, err
	}
	handle, err := syscall.CreateFile(pointer, syscall.GENERIC_READ|syscall.GENERIC_WRITE, 0, nil, syscall.OPEN_ALWAYS, syscall.FILE_ATTRIBUTE_NORMAL, 0)
	switch {
	case err == windows.ERROR_SHARING_VIOLATION:
		return nil, true, nil
	case err != nil:
		return nil, false, err
	}
	return func() { syscall.CloseHandle(handle) }, false, nil
}

//go:build windows

package state

import (
	"fmt"
	"os"
	"path/filepath"

	"golang.org/x/sys/windows"
)

// Lease reports whether a server holds the Serving lease. PHP's flock locks
// the file's whole range with LockFileEx here, so a lock on its first byte
// conflicts. No lease file means no server ever ran from the directory.
func (d Dir) Lease() (bool, error) {
	path := d.lease()
	if info, err := os.Stat(path); err != nil || !info.Mode().IsRegular() {
		return false, nil
	}
	refused := fmt.Errorf("Cannot open the serving lease: %s", filepath.ToSlash(path))
	pointer, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return false, refused
	}
	// PHP opens the lease write-only and shares reading and writing, so this
	// open does the same.
	handle, err := windows.CreateFile(pointer, windows.GENERIC_WRITE,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE, nil, windows.OPEN_EXISTING, windows.FILE_ATTRIBUTE_NORMAL, 0)
	if err != nil {
		return false, refused
	}
	defer windows.CloseHandle(handle)
	overlapped := new(windows.Overlapped)
	if err := windows.LockFileEx(handle, windows.LOCKFILE_EXCLUSIVE_LOCK|windows.LOCKFILE_FAIL_IMMEDIATELY, 0, 1, 0, overlapped); err != nil {
		return true, nil
	}
	windows.UnlockFileEx(handle, 0, 1, 0, overlapped)
	return false, nil
}

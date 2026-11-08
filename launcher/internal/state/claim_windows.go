//go:build windows

package state

import (
	"fmt"
	"path/filepath"

	"golang.org/x/sys/windows"
)

// Lease is a held Serving lease. A LockFileEx lock ends with the handle that
// took it, so the launcher keeps this handle until the server it waits for exits.
type Lease struct {
	handle windows.Handle
}

// Claim locks the file's whole range, as PHP's flock does. It returns nil when
// another server holds the lease.
func (d Dir) Claim() (*Lease, error) {
	path := d.lease()
	pointer, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return nil, fmt.Errorf("Cannot open the serving lease: %s", filepath.ToSlash(path))
	}
	handle, err := windows.CreateFile(pointer, windows.GENERIC_READ|windows.GENERIC_WRITE,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE, nil, windows.OPEN_ALWAYS, windows.FILE_ATTRIBUTE_NORMAL, 0)
	if err != nil {
		return nil, fmt.Errorf("Cannot open the serving lease: %s", filepath.ToSlash(path))
	}
	if err := windows.LockFileEx(handle, windows.LOCKFILE_EXCLUSIVE_LOCK|windows.LOCKFILE_FAIL_IMMEDIATELY, 0, 0xFFFFFFFF, 0xFFFFFFFF, new(windows.Overlapped)); err != nil {
		windows.CloseHandle(handle)
		return nil, nil
	}
	return &Lease{handle: handle}, nil
}

// Inherit does nothing: the launcher waits for the server, so its handle holds the lease.
func (l *Lease) Inherit() error {
	return nil
}

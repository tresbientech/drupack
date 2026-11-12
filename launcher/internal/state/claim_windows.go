//go:build windows

package state

import (
	"errors"
	"io/fs"
	"os"

	"golang.org/x/sys/windows"
)

// Lease is a held Serving lease. A LockFileEx lock ends with the handle that
// took it, so the launcher keeps this handle until the server it waits for exits.
type Lease struct {
	handle windows.Handle
}

// noFollow is 0: Windows has no open flag that refuses a link. OpenFile's Lstat
// refuses one, and only an account that can already write the folder can swap
// one in after it.
const noFollow = 0

// lockFile opens the lease and locks its whole range without waiting, as PHP's
// flock does. The handle is 0 when another server holds it. PHP opens the file
// and shares reading and writing, so these opens do the same. A reparse point
// at the lease opens as itself, and is refused.
func lockFile(path string, access, disposition uint32) (windows.Handle, error) {
	pointer, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return 0, leaseRefusal(path, err)
	}
	handle, err := windows.CreateFile(pointer, access,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE, nil, disposition,
		windows.FILE_ATTRIBUTE_NORMAL|windows.FILE_FLAG_OPEN_REPARSE_POINT, 0)
	if err != nil {
		return 0, leaseRefusal(path, err)
	}
	var info windows.ByHandleFileInformation
	err = windows.GetFileInformationByHandle(handle, &info)
	if err == nil && info.FileAttributes&windows.FILE_ATTRIBUTE_REPARSE_POINT != 0 {
		err = errors.New("a link stands at the path")
	}
	if err != nil {
		windows.CloseHandle(handle)
		return 0, leaseRefusal(path, err)
	}
	if err := windows.LockFileEx(handle, windows.LOCKFILE_EXCLUSIVE_LOCK|windows.LOCKFILE_FAIL_IMMEDIATELY, 0, 0xFFFFFFFF, 0xFFFFFFFF, new(windows.Overlapped)); err != nil {
		windows.CloseHandle(handle)
		return 0, nil
	}
	return handle, nil
}

// Claim takes the lease. It returns nil when another server holds it.
func (d Dir) Claim() (*Lease, error) {
	handle, err := lockFile(d.lease(), windows.GENERIC_READ|windows.GENERIC_WRITE, windows.OPEN_ALWAYS)
	if handle == 0 {
		return nil, err
	}
	return &Lease{handle}, nil
}

// Lease reports whether a server holds the Serving lease. No lease file means
// no server ever ran from the directory.
func (d Dir) Lease() (bool, error) {
	info, err := os.Stat(d.lease())
	switch {
	case errors.Is(err, fs.ErrNotExist):
		return false, nil
	case err != nil:
		// os.Stat returns a *PathError, whose cause names no path.
		return false, leaseRefusal(d.lease(), errors.Unwrap(err))
	case !info.Mode().IsRegular():
		return false, nil
	}
	handle, err := lockFile(d.lease(), windows.GENERIC_WRITE, windows.OPEN_EXISTING)
	if handle == 0 {
		return err == nil, err
	}
	return false, windows.CloseHandle(handle)
}

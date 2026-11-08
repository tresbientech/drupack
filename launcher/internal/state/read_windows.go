//go:build windows

package state

import (
	"io"
	"os"

	"golang.org/x/sys/windows"
)

// readShared reads path under a shared lock. PHP writes a record under an
// exclusive one, so the read waits for the whole record. The handle shares
// deletion, so a start can rename a new record over the one this reads.
func readShared(path string) ([]byte, error) {
	pointer, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return nil, err
	}
	opened, err := windows.CreateFile(pointer, windows.GENERIC_READ,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE|windows.FILE_SHARE_DELETE, nil, windows.OPEN_EXISTING, windows.FILE_ATTRIBUTE_NORMAL, 0)
	if err != nil {
		return nil, &os.PathError{Op: "open", Path: path, Err: err}
	}
	file := os.NewFile(uintptr(opened), path)
	defer file.Close()
	overlapped := new(windows.Overlapped)
	handle := windows.Handle(file.Fd())
	if err := windows.LockFileEx(handle, 0, 0, 1, 0, overlapped); err != nil {
		return nil, err
	}
	defer windows.UnlockFileEx(handle, 0, 1, 0, overlapped)
	return io.ReadAll(file)
}

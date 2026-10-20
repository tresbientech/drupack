//go:build windows

package runtime

import (
	"path/filepath"
	"testing"

	"golang.org/x/sys/windows"
)

// holdLease takes the Serving lease in entry as PHP's flock does, locking the
// whole range, until the test ends. LockFileEx conflicts between two handles of
// one process, so no child is needed. The cleanup closes the handle before
// t.TempDir removes the directory.
func holdLease(t *testing.T, entry string) {
	t.Helper()
	pointer, err := windows.UTF16PtrFromString(filepath.Join(entry, leaseName))
	if err != nil {
		t.Fatal(err)
	}
	handle, err := windows.CreateFile(pointer, windows.GENERIC_READ|windows.GENERIC_WRITE,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE, nil, windows.OPEN_ALWAYS, windows.FILE_ATTRIBUTE_NORMAL, 0)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { windows.CloseHandle(handle) })
	if err := windows.LockFileEx(handle, windows.LOCKFILE_EXCLUSIVE_LOCK|windows.LOCKFILE_FAIL_IMMEDIATELY, 0, 0xFFFFFFFF, 0xFFFFFFFF, new(windows.Overlapped)); err != nil {
		t.Fatal(err)
	}
}

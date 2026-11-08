//go:build windows

// Package statetest holds the Serving lease the way PHP's Serving class does,
// for the tests of the packages that read it.
package statetest

import (
	"path/filepath"
	"sync"
	"testing"

	"golang.org/x/sys/windows"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// HoldLease takes the Serving lease in dir as PHP's flock does, locking the
// whole range, until release or the test's end. LockFileEx conflicts between
// two handles of one process, so no child is needed. The cleanup closes the
// handle before t.TempDir removes the directory.
func HoldLease(t *testing.T, dir string) (release func()) {
	t.Helper()
	pointer, err := windows.UTF16PtrFromString(filepath.Join(dir, state.LeaseName))
	if err != nil {
		t.Fatal(err)
	}
	handle, err := windows.CreateFile(pointer, windows.GENERIC_READ|windows.GENERIC_WRITE,
		windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE, nil, windows.OPEN_ALWAYS, windows.FILE_ATTRIBUTE_NORMAL, 0)
	if err != nil {
		t.Fatal(err)
	}
	release = sync.OnceFunc(func() { windows.CloseHandle(handle) })
	t.Cleanup(release)
	if err := windows.LockFileEx(handle, windows.LOCKFILE_EXCLUSIVE_LOCK|windows.LOCKFILE_FAIL_IMMEDIATELY, 0, 0xFFFFFFFF, 0xFFFFFFFF, new(windows.Overlapped)); err != nil {
		t.Fatal(err)
	}
	return release
}

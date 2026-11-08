//go:build windows

package state

import (
	"io"
	"os"

	"golang.org/x/sys/windows"
)

// readShared reads path under a shared lock. PHP writes a record under an
// exclusive one, so the read waits for the whole record.
func readShared(path string) ([]byte, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer file.Close()
	overlapped := new(windows.Overlapped)
	handle := windows.Handle(file.Fd())
	if err := windows.LockFileEx(handle, 0, 0, 1, 0, overlapped); err != nil {
		return nil, err
	}
	defer windows.UnlockFileEx(handle, 0, 1, 0, overlapped)
	return io.ReadAll(file)
}

//go:build unix

package runtime

import (
	"os"
	"path/filepath"
	"syscall"
)

// lockRoot serializes staging across every process sharing root. Blocking is
// correct here: the kernel releases a dead holder's lock.
func lockRoot(root string) (func(), error) {
	file, err := os.OpenFile(filepath.Join(root, "lock"), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return nil, err
	}
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX); err != nil {
		file.Close()
		return nil, err
	}
	return func() {
		syscall.Flock(int(file.Fd()), syscall.LOCK_UN)
		file.Close()
	}, nil
}

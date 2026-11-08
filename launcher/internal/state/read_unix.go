//go:build unix

package state

import (
	"io"
	"os"
	"syscall"
)

// readShared reads path under a shared lock. PHP writes a record under an
// exclusive one, so the read waits for the whole record.
func readShared(path string) ([]byte, error) {
	file, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer file.Close()
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_SH); err != nil {
		return nil, err
	}
	return io.ReadAll(file)
}

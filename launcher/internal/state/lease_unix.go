//go:build unix

package state

import (
	"fmt"
	"os"
	"syscall"
)

// Lease reports whether a server holds the Serving lease. PHP's flock is
// flock(2) here. No lease file means no server ever ran from the directory.
// PHP opens the lease write-only, so this open asks for no more.
func (d Dir) Lease() (bool, error) {
	path := d.lease()
	if info, err := os.Stat(path); err != nil || !info.Mode().IsRegular() {
		return false, nil
	}
	file, err := os.OpenFile(path, os.O_WRONLY, 0)
	if err != nil {
		return false, fmt.Errorf("Cannot open the serving lease: %s", path)
	}
	defer file.Close()
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		return true, nil
	}
	syscall.Flock(int(file.Fd()), syscall.LOCK_UN)
	return false, nil
}

//go:build unix

package state

import (
	"fmt"
	"os"
	"syscall"
)

// Lease is a held Serving lease. The kernel releases it when the process that
// holds the descriptor ends, a kill included.
type Lease struct {
	file *os.File
}

// Claim takes the lease with flock(2), as PHP's Serving class does. It returns
// nil when another server holds it.
func (d Dir) Claim() (*Lease, error) {
	path := d.lease()
	file, err := os.OpenFile(path, os.O_RDWR|os.O_CREATE, 0o600)
	if err != nil {
		return nil, fmt.Errorf("Cannot open the serving lease: %s", path)
	}
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		file.Close()
		return nil, nil
	}
	return &Lease{file: file}, nil
}

// Inherit lets the descriptor survive an exec, since Go opens files
// close-on-exec. The lock lives on the open file description, so the program
// the process becomes holds it.
func (l *Lease) Inherit() error {
	_, _, errno := syscall.Syscall(syscall.SYS_FCNTL, l.file.Fd(), syscall.F_SETFD, 0)
	if errno != 0 {
		return errno
	}
	return nil
}

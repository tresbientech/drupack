//go:build unix

package state

import (
	"errors"
	"io/fs"
	"os"
	"syscall"
)

// Lease is a held Serving lease. The kernel releases it when the process that
// holds the descriptor ends, a kill included. The descriptor is not
// close-on-exec, so every child of the claimer, and the server the claimer
// execs into, holds the lock with it.
type Lease struct {
	file *os.File
}

// lockFile opens the lease with flags and takes flock(2) on it without waiting,
// as the previous release's PHP does. file is nil when another server holds it.
func lockFile(path string, flags int) (file *os.File, err error) {
	fd, err := syscall.Open(path, flags, 0o600)
	if err != nil {
		return nil, leaseRefusal(path)
	}
	if err := syscall.Flock(fd, syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		syscall.Close(fd)
		return nil, nil
	}
	return os.NewFile(uintptr(fd), path), nil
}

// Claim takes the lease. It returns nil when another server holds it.
func (d Dir) Claim() (*Lease, error) {
	file, err := lockFile(d.lease(), os.O_RDWR|os.O_CREATE)
	if file == nil {
		return nil, err
	}
	return &Lease{file}, nil
}

// Lease reports whether a server holds the Serving lease. No lease file means
// no server ever ran from the directory, and a state path that names a file
// holds none. PHP opens the lease write-only, so
// this open asks for no more.
func (d Dir) Lease() (bool, error) {
	info, err := os.Stat(d.lease())
	switch {
	case errors.Is(err, fs.ErrNotExist) || errors.Is(err, syscall.ENOTDIR):
		return false, nil
	case err != nil:
		return false, leaseRefusal(d.lease())
	case !info.Mode().IsRegular():
		return false, nil
	}
	file, err := lockFile(d.lease(), os.O_WRONLY)
	if file == nil {
		return err == nil, err
	}
	return false, file.Close()
}

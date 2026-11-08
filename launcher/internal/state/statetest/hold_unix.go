//go:build unix

// Package statetest holds the Serving lease the way the previous release's PHP does, with flock,
// for the tests of the packages that read it.
package statetest

import (
	"os"
	"path/filepath"
	"sync"
	"syscall"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// HoldLease takes the Serving lease in dir as PHP's flock does, until release
// or the test's end. flock conflicts between two descriptors of one process,
// so no child is needed.
func HoldLease(t *testing.T, dir string) (release func()) {
	t.Helper()
	file, err := os.OpenFile(filepath.Join(dir, state.LeaseName), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		t.Fatal(err)
	}
	release = sync.OnceFunc(func() { file.Close() })
	t.Cleanup(release)
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		t.Fatal(err)
	}
	return release
}

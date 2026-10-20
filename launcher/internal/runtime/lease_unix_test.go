//go:build unix

package runtime

import (
	"os"
	"path/filepath"
	"syscall"
	"testing"
)

// holdLease takes the Serving lease in entry as serve.php does, until the test ends.
// flock conflicts between two descriptors of one process, so no child is needed.
func holdLease(t *testing.T, entry string) {
	t.Helper()
	file, err := os.OpenFile(filepath.Join(entry, leaseName), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { file.Close() })
	if err := syscall.Flock(int(file.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		t.Fatal(err)
	}
}

//go:build unix

package state

import (
	"syscall"
	"testing"
)

// Go opens files close-on-exec, which would drop the lease at the exec into the server.
func TestInheritClearsCloseOnExec(t *testing.T) {
	lease, err := At(t.TempDir()).Claim()
	if err != nil || lease == nil {
		t.Fatalf("Claim() = %v, %v", lease, err)
	}
	flags := func() uintptr {
		value, _, errno := syscall.Syscall(syscall.SYS_FCNTL, lease.file.Fd(), syscall.F_GETFD, 0)
		if errno != 0 {
			t.Fatal(errno)
		}
		return value
	}
	if flags()&syscall.FD_CLOEXEC == 0 {
		t.Fatal("the lease descriptor is not close-on-exec before Inherit")
	}
	if err := lease.Inherit(); err != nil {
		t.Fatal(err)
	}
	if flags()&syscall.FD_CLOEXEC != 0 {
		t.Error("the lease descriptor is still close-on-exec")
	}
}

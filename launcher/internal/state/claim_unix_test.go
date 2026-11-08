//go:build unix

package state

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
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

// leaseHolderDir names the Site data a re-run of this test binary claims, then
// keeps across its exec into a shell.
const leaseHolderDir = "DRUPACK_TEST_LEASE_HOLDER"

// The detached start claims the lease once and becomes the server through an
// exec, so the program it becomes must hold the lease for every other process.
func TestAProcessExecdWithTheLeaseHoldsIt(t *testing.T) {
	if dir := os.Getenv(leaseHolderDir); dir != "" {
		lease, err := At(dir).Claim()
		if err != nil || lease == nil {
			fmt.Printf("Claim() = %v, %v\n", lease, err)
			os.Exit(1)
		}
		if err := lease.Inherit(); err != nil {
			fmt.Println(err)
			os.Exit(1)
		}
		err = syscall.Exec("/bin/sh", []string{"sh", "-c", "echo held; read line; exit 0"}, os.Environ())
		fmt.Println(err)
		os.Exit(1)
	}
	dir := t.TempDir()
	holder := exec.Command(os.Args[0], "-test.run=^TestAProcessExecdWithTheLeaseHoldsIt$")
	holder.Env = append(os.Environ(), leaseHolderDir+"="+dir)
	stdin, err := holder.StdinPipe()
	if err != nil {
		t.Fatal(err)
	}
	stdout, err := holder.StdoutPipe()
	if err != nil {
		t.Fatal(err)
	}
	if err := holder.Start(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { holder.Process.Kill(); holder.Wait() })
	if line, err := bufio.NewReader(stdout).ReadString('\n'); line != "held\n" {
		t.Fatalf("the holder printed %q, %v", line, err)
	}
	if held, err := At(dir).Lease(); err != nil || !held {
		t.Fatalf("Lease() = %v, %v while the exec'd shell runs, want held", held, err)
	}
	if lease, err := At(dir).Claim(); err != nil || lease != nil {
		t.Fatalf("Claim() = %v, %v while the exec'd shell runs, want refused", lease, err)
	}
	stdin.Close()
	if err := holder.Wait(); err != nil {
		t.Fatal(err)
	}
	if held, err := At(dir).Lease(); err != nil || held {
		t.Fatalf("Lease() = %v, %v once the shell exited, want free", held, err)
	}
}

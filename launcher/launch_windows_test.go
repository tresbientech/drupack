//go:build windows

package main

import (
	"errors"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"syscall"
	"testing"
	"time"

	"golang.org/x/sys/windows"
)

const (
	roleVariable = "DRUPACK_TEST_ROLE"
	dirVariable  = "DRUPACK_TEST_DIR"
	childExit    = 7
)

// Ctrl+Break reaches the launcher and the server alike. The launcher holds the
// Serving lease, so it must outlive the signal until the server exits.
func TestLaunchOutlivesCtrlBreakUntilTheChildExits(t *testing.T) {
	dir := os.Getenv(dirVariable)
	switch os.Getenv(roleVariable) {
	case "launcher":
		self, err := os.Executable()
		if err != nil {
			t.Fatal(err)
		}
		t.Fatal(launch(Command{
			Path: self,
			Args: []string{self, "-test.run=^" + t.Name() + "$"},
			Env:  append(os.Environ(), roleVariable+"=child"),
		}))
	case "child":
		interrupt := make(chan os.Signal, 1)
		signal.Notify(interrupt, os.Interrupt)
		if err := os.WriteFile(filepath.Join(dir, "ready"), nil, 0o644); err != nil {
			t.Fatal(err)
		}
		<-interrupt
		time.Sleep(2 * time.Second)
		if err := os.WriteFile(filepath.Join(dir, "exited"), nil, 0o644); err != nil {
			t.Fatal(err)
		}
		os.Exit(childExit)
	}

	dir = t.TempDir()
	self, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	launcher := exec.Command(self, "-test.run=^"+t.Name()+"$")
	launcher.Env = append(os.Environ(), roleVariable+"=launcher", dirVariable+"="+dir)
	launcher.SysProcAttr = &syscall.SysProcAttr{CreationFlags: windows.CREATE_NEW_PROCESS_GROUP}
	if err := launcher.Start(); err != nil {
		t.Fatal(err)
	}
	done := make(chan error, 1)
	go func() { done <- launcher.Wait() }()
	t.Cleanup(func() {
		launcher.Process.Kill()
	})

	ready := filepath.Join(dir, "ready")
	deadline := time.Now().Add(20 * time.Second)
	for {
		if _, err := os.Stat(ready); err == nil {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("the child never wrote its ready marker")
		}
		time.Sleep(50 * time.Millisecond)
	}

	if err := windows.GenerateConsoleCtrlEvent(windows.CTRL_BREAK_EVENT, uint32(launcher.Process.Pid)); err != nil {
		t.Fatal(err)
	}
	select {
	case err := <-done:
		t.Fatalf("the launcher exited on Ctrl+Break while its child still ran: %v", err)
	case <-time.After(time.Second):
	}
	var exit *exec.ExitError
	select {
	case err := <-done:
		if !errors.As(err, &exit) || exit.ExitCode() != childExit {
			t.Fatalf("the launcher ended with %v, want exit code %d", err, childExit)
		}
	case <-time.After(20 * time.Second):
		t.Fatal("the launcher never exited after its child")
	}
	if _, err := os.Stat(filepath.Join(dir, "exited")); err != nil {
		t.Errorf("the launcher exited before its child did: %v", err)
	}
}

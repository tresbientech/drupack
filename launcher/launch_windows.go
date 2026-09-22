//go:build windows

package main

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"unsafe"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// launch starts executable as a child, since Windows has no process image
// replacement, and reports its outcome through this process's own exit.
func launch(executable string, args []string) error {
	command := exec.Command(executable, args[1:]...)
	command.Stdin = os.Stdin
	command.Stdout = os.Stdout
	command.Stderr = os.Stderr
	// php.ini and the bundled trust store load through the same environment
	// rules launch_unix.go applies, so the two entry points cannot drift apart.
	command.Env = runtime.Environment(filepath.Dir(executable), os.Environ())
	if consoleOwned() {
		command.Env = append(command.Env, "DRUPACK_RUNTIME_CONSOLE_OWNED=1")
	}

	err := command.Run()
	if err == nil {
		os.Exit(0)
	}
	var exitError *exec.ExitError
	if errors.As(err, &exitError) {
		holdConsole()
		os.Exit(exitError.ExitCode())
	}
	return err
}

// consoleOwned reports whether this process is alone on its console, which
// means a file manager created the window. A console from a shell also holds
// the shell.
func consoleOwned() bool {
	var process uint32
	count, _, _ := syscall.NewLazyDLL("kernel32.dll").NewProc("GetConsoleProcessList").
		Call(uintptr(unsafe.Pointer(&process)), 1)
	return count == 1
}

// holdConsole keeps a file manager's window open so its reader sees a
// failure before Windows closes the window; a shell's own window already
// stays open.
func holdConsole() {
	if !consoleOwned() {
		return
	}
	fmt.Fprint(os.Stderr, "Press Enter to close this window.")
	fmt.Fscanln(os.Stdin)
}

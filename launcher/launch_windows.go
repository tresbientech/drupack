//go:build windows

package main

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"syscall"
	"unsafe"
)

// foldCase is true: Windows variable names are case-insensitive.
const foldCase = true

// launch starts the command as a child, since Windows has no process image
// replacement, and reports its outcome through this process's own exit.
func launch(c Command) error {
	command := exec.Command(c.Path, c.Args[1:]...)
	command.Stdin = os.Stdin
	command.Stdout = os.Stdout
	command.Stderr = os.Stderr
	command.Env = c.Env

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

// createNoWindow gives a process a console no window shows. syscall does not define it.
const createNoWindow = 0x08000000

// detachedProcess starts the server with a hidden console, which its own console
// children, cron and Drush, share instead of opening windows of their own.
func detachedProcess() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{CreationFlags: createNoWindow}
}

// consoleOwned reports whether this process is alone on its console, which
// means a file manager created the window, unless detach.go started it. A
// console from a shell also holds the shell.
func consoleOwned() bool {
	var process uint32
	count, _, _ := syscall.NewLazyDLL("kernel32.dll").NewProc("GetConsoleProcessList").
		Call(uintptr(unsafe.Pointer(&process)), 1)
	return ownsConsole(os.Getenv("DRUPACK_RUNTIME_DETACHED"), uint32(count))
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

// replaceExecutable moves the running path aside to path.old, which Windows
// allows while the file runs, then renames staged into place. A failed second
// rename moves the old file back.
func replaceExecutable(path, staged string) error {
	if err := os.Rename(path, path+".old"); err != nil {
		return fmt.Errorf("cannot move %s aside to %s.old, which a server it started may still run. Stop that server, then run self-update again: %w", path, path, err)
	}
	if err := os.Rename(staged, path); err != nil {
		if restore := os.Rename(path+".old", path); restore != nil {
			return fmt.Errorf("%w; %s is now %s.old", err, path, path)
		}
		return err
	}
	return nil
}

// removeReplaced removes the file an earlier self-update moved aside. A server
// still running it keeps it until a later run.
func removeReplaced(path string) {
	os.Remove(path + ".old")
}

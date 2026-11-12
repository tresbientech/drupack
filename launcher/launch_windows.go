//go:build windows

package main

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/fsguard"
)

const foldCase = true

// nodeFiles are each Node program's file: npm and npx are batch files.
var nodeFiles = map[string]string{"node": "node.exe", "npm": "npm.cmd", "npx": "npx.cmd"}

func nodeFile(word string) string {
	return nodeFiles[word]
}

// phpBinary is php.exe beside the runtime, which runs the runtime's php word. PHP's
// is_executable refuses a batch file, so Composer cannot run bin/php.cmd.
func phpBinary(l Launch) string {
	return filepath.Join(filepath.Dir(l.Entry), "php.exe")
}

// launch starts the command as a child, since Windows cannot replace a process image.
func launch(c Command) error {
	command := exec.Command(c.Path, c.Args[1:]...)
	command.Stdin = os.Stdin
	command.Stdout = os.Stdout
	command.Stderr = os.Stderr
	command.Env = c.Env
	command.Dir = c.Dir
	// The child shares the console and gets a Ctrl+C itself. This process waits
	// for it, so its handle holds the lease until the server exits.
	signal.Notify(make(chan os.Signal, 1), os.Interrupt)
	err := command.Run()
	if child, ok := errors.AsType[*exec.ExitError](err); ok {
		return exitError(child.ExitCode())
	}
	return err
}

// restrictUmask does nothing: Windows has no umask.
func restrictUmask() {}

// fixCommand is the advice that ends e, which on Windows names an owner alone.
func fixCommand(e *fsguard.ExposedError) string {
	return "Move the Site data into a folder you own."
}

// detachedProcess starts a background process with a hidden console, which a
// detached start's server and its console children, cron and Drush, share
// instead of opening windows of their own.
func detachedProcess() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{CreationFlags: windows.CREATE_NO_WINDOW}
}

// consoleOwned reports whether this process is alone on its console, which
// means a file manager created the window, unless a detaching start created
// this process: detached. A console from a shell also holds the shell.
func consoleOwned(detached bool) bool {
	var process uint32
	count, _, _ := syscall.NewLazyDLL("kernel32.dll").NewProc("GetConsoleProcessList").
		Call(uintptr(unsafe.Pointer(&process)), 1)
	return ownsConsole(detached, uint32(count))
}

// writeTo makes file the stdout and stderr this process prints to and hands
// the processes it starts, and closes the pipes to the parent.
func writeTo(file *os.File) error {
	os.Stdout.Close()
	os.Stderr.Close()
	os.Stdout, os.Stderr = file, file
	return nil
}

// holdConsole keeps a file manager's window open so its reader sees a failure.
func holdConsole(detached bool) {
	if !consoleOwned(detached) {
		return
	}
	fmt.Fprint(os.Stderr, "Press Enter to close this window.")
	_, _ = fmt.Fscanln(os.Stdin)
}

// replaceExecutable moves the running path aside to path.old, which Windows allows
// while the file runs. A failed second rename moves it back.
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

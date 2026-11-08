//go:build unix

package main

import (
	"os"
	"syscall"

	"golang.org/x/sys/unix"
)

// foldCase is false: Unix variable names are case-sensitive.
const foldCase = false

// nodeFile is the program's own name: npm and npx are scripts Node runs.
func nodeFile(word string) string {
	return word
}

func launch(c Command) error {
	return syscall.Exec(c.Path, c.Args, c.Env)
}

// restrictUmask keeps the files a command creates to its owner, as every start does.
func restrictUmask() {
	syscall.Umask(0o077)
}

// consoleOwned is false: a Unix terminal belongs to the shell that started it.
func consoleOwned() bool {
	return false
}

// detachedProcess starts a background process in a session of its own, so the
// terminal's hangup and interrupt never reach it, or the server a detached
// start's child becomes.
func detachedProcess() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{Setsid: true}
}

// writeTo makes file this process's stdout and stderr, which the processes it
// starts and the server it execs inherit.
func writeTo(file *os.File) error {
	defer file.Close()
	for _, descriptor := range []int{1, 2} {
		if err := unix.Dup2(int(file.Fd()), descriptor); err != nil {
			return err
		}
	}
	return nil
}

// holdConsole does nothing: a terminal stays open on its own once the shell
// that launched it regains control.
func holdConsole() {}

// replaceExecutable renames staged over path. A process running the old file
// keeps its open copy.
func replaceExecutable(path, staged string) error {
	return os.Rename(staged, path)
}

// removeReplaced does nothing: a Unix replacement leaves no old file behind.
func removeReplaced(string) {}

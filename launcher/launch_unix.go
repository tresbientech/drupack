//go:build unix

package main

import (
	"os"
	"syscall"
)

// foldCase is false: Unix variable names are case-sensitive.
const foldCase = false

func launch(c Command) error {
	return syscall.Exec(c.Path, c.Args, c.Env)
}

// consoleOwned is false: a Unix terminal belongs to the shell that started it.
func consoleOwned() bool {
	return false
}

// detachedProcess starts the server in a session of its own, so the terminal's
// hangup and interrupt never reach it.
func detachedProcess() *syscall.SysProcAttr {
	return &syscall.SysProcAttr{Setsid: true}
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

//go:build unix

package main

import (
	"os"
	"syscall"
)

func launch(executable string, args []string) error {
	return syscall.Exec(executable, args, os.Environ())
}

// holdConsole does nothing: a terminal stays open on its own once the shell
// that launched it regains control.
func holdConsole() {}

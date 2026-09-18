//go:build unix

package main

import (
	"os"
	"syscall"
)

func launch(executable string, args []string) error {
	return syscall.Exec(executable, args, os.Environ())
}

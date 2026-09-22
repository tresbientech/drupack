//go:build unix

package main

import (
	"os"
	"path/filepath"
	"syscall"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

func launch(executable string, args []string) error {
	env := runtime.Environment(filepath.Dir(executable), os.Environ())
	return syscall.Exec(executable, args, env)
}

// holdConsole does nothing: a terminal stays open on its own once the shell
// that launched it regains control.
func holdConsole() {}

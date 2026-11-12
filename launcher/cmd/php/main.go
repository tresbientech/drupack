// Command php is php.exe beside the Windows runtime. Composer runs it as
// PHP_BINARY, since PHP's is_executable refuses a batch file such as bin/php.cmd.
// It runs the runtime's php word with its own arguments and exits with its status.
package main

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/payload"
)

func main() {
	executable, err := os.Executable()
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	os.Exit(run(filepath.Join(filepath.Dir(executable), payload.WindowsEntry), os.Args[1:]))
}

// run starts runtime's php word with arguments, in this process's directory and
// environment, and returns its exit status.
func run(runtime string, arguments []string) int {
	command := exec.Command(runtime, append([]string{"php"}, arguments...)...) //nolint:gosec // G702: the runtime beside this file gets the caller's own arguments, with no shell.
	command.Stdin, command.Stdout, command.Stderr = os.Stdin, os.Stdout, os.Stderr
	// The child shares the console and gets a Ctrl+C itself.
	interrupts := make(chan os.Signal, 1)
	signal.Notify(interrupts, os.Interrupt)
	defer signal.Stop(interrupts)
	err := command.Run()
	if exited, ok := errors.AsType[*exec.ExitError](err); ok {
		return exited.ExitCode()
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		return 1
	}
	return 0
}

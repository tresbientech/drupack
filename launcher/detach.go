package main

import (
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"strings"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// What the relay matches in the server's log. runtime/watch/watch.go prints both, and
// detach_test.go reads that file to check the text is still there.
const (
	readyMarker   = " is ready."
	timeoutMarker = "Drupal did not answer at"
)

const relayPoll = 100 * time.Millisecond

// detach runs this executable again with the arguments after `--` as a background
// server logging to the log file, and relays the log until the server answers or exits.
// The caller names the command that stops it. launch.php and serve.php run this word
// only once they hold no lease, so the server it starts takes the lease.
func detach(arguments []string) error {
	if len(arguments) < 4 || arguments[2] != "--" {
		return errors.New("detach takes LOG STOP_COMMAND -- ARGUMENTS")
	}
	log, stop, server := arguments[0], arguments[1], arguments[3:]
	executable, err := os.Executable()
	if err != nil {
		return err
	}
	file, err := os.OpenFile(log, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}
	// A nil Stdin is the null device, since nothing answers a terminal prompt here.
	command := exec.Command(executable, server...)
	command.Stdout, command.Stderr = file, file
	// launch.php runs this word from the application directory because PHP's chdir moves only PHP's view,
	// and the server resolves a relative --data-dir from where it starts. The engine executable
	// records no start directory.
	command.Dir = os.Getenv("DRUPACK_RUNTIME_CWD")
	// The child's hidden console holds the child alone, which launch_windows.go would
	// otherwise read as a file manager's window. The server reopens the log by this path.
	command.Env = append(os.Environ(), "DRUPACK_RUNTIME_DETACHED="+log)
	command.SysProcAttr = detachedProcess()
	if err := command.Start(); err != nil {
		file.Close()
		return err
	}
	file.Close()
	exited := make(chan int, 1)
	go func() {
		command.Wait()
		code := command.ProcessState.ExitCode()
		// A process a signal ended reports -1.
		if code < 0 {
			code = 1
		}
		exited <- code
	}()
	colour := runtime.Terminal(os.Stdout) && os.Getenv("NO_COLOR") == ""
	code := relay(log, exited, os.Stdout, stop, colour, relayPoll)
	if code != 0 {
		os.Exit(code)
	}
	return nil
}

// relay copies the log to out line by line and returns the start's exit code: 0 at the ready
// line (not copied), the server's code if it exits first (after the rest of the log), and 1 at
// the timeout line, since the server still runs.
func relay(log string, exited <-chan int, out io.Writer, stop string, colour bool, poll time.Duration) int {
	file, err := os.Open(log)
	if err != nil {
		panic(err)
	}
	defer file.Close()
	if colour {
		stop = "\x1b[1;32m" + stop + "\x1b[0m"
	}
	var pending string
	blank := false
	for {
		// The exit is read before the log, so the log holds everything the server wrote.
		var code int
		gone := false
		select {
		case code = <-exited:
			gone = true
		default:
		}
		content, err := io.ReadAll(file)
		if err != nil {
			panic(err)
		}
		pending += string(content)
		for {
			end := strings.IndexByte(pending, '\n')
			if end < 0 {
				break
			}
			line := pending[:end+1]
			pending = pending[end+1:]
			switch {
			case strings.Contains(line, timeoutMarker):
				fmt.Fprint(out, line)
				fmt.Fprintf(out, "\nThe server still runs. Stop it with:\n\n    %s\n\n", stop)
				return 1
			case strings.Contains(line, readyMarker):
				if !blank {
					fmt.Fprintln(out)
				}
				fmt.Fprintf(out, "%s runs in the background. Its log: %s\n\nStop it with:\n\n    %s\n\n", siteName, log, stop)
				return 0
			default:
				fmt.Fprint(out, line)
				blank = line == "\n"
			}
		}
		if gone {
			fmt.Fprint(out, pending)
			return code
		}
		time.Sleep(poll)
	}
}

// ownsConsole reports whether a file manager created this process's console: nothing
// but this process holds it, and a detached server's hidden console is not one.
func ownsConsole(detached string, processes uint32) bool {
	return detached == "" && processes == 1
}

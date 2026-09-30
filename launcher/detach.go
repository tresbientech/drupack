package main

import (
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"strings"
	"time"
)

// What the relay matches in the server's log. runtime/watch/watch.go prints both, and
// detach_test.go reads that file to check the text is still there.
const (
	readyMarker   = " is ready."
	timeoutMarker = "Drupal did not answer at"
)

// relayPoll is how often the relay reads the log for new lines.
const relayPoll = 100 * time.Millisecond

// detach runs this executable again with the arguments after `--`, as a background
// server that writes to the log file, and relays that log to the reader until the
// server answers or exits. The caller names the command that stops it, since only the
// caller knows which Site data or folder the reader named. launch.php runs this word once it holds no lease and has
// checked the listener, so the server it starts is the one that takes the lease.
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
	info, err := os.Stdout.Stat()
	if err != nil {
		return err
	}
	colour := info.Mode()&os.ModeCharDevice != 0 && os.Getenv("NO_COLOR") == ""
	code := relay(log, exited, os.Stdout, stop, colour, relayPoll)
	if code != 0 {
		os.Exit(code)
	}
	return nil
}

// relay copies the log to out, line by line, and returns the exit code of the start.
// The ready line ends the relay with 0 and is not copied, since the summary that
// follows replaces it. A server that exits first ends it with the server's code,
// after the rest of the log. The timeout line ends it with 1, since the server
// still runs and the reader has to stop it.
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

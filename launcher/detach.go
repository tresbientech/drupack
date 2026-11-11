package main

import (
	"bufio"
	"fmt"
	"io"
	"os"
	"os/exec"
	"os/signal"
	"regexp"
	"slices"
	"strings"
	"syscall"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// What the relay matches in the server's log. runtime/watch/watch.go prints it, and
// detach_test.go reads that file to check the text is still there.
const readyMarker = " is ready."

const relayPoll = 100 * time.Millisecond

// childWord opens the line a detaching start gives its child, before the
// reader's words: LOG PERSON. LOG is the server log the child writes once it
// holds the lease; PERSON is 1 when a terminal started the parent. The leading
// dashes keep it apart from a folder the engine serves, and the help names it nowhere.
const childWord = "--detached-child"

// logMarker is the line a detached child prints on its stdout once it holds
// the lease and writes to the server log. No line a start prints holds a NUL.
const logMarker = "\x00log\n"

// detachedChild is what the detaching parent passed this process.
type detachedChild struct {
	log    string
	person bool
}

// child is nil for a start the reader ran.
var child *detachedChild

// takeChild reads and strips the words a detaching parent puts before the
// reader's.
func takeChild(args []string) []string {
	if len(args) < 4 || args[1] != childWord {
		return args
	}
	child = &detachedChild{log: args[2], person: args[3] == "1"}
	// A reader's Ctrl+C ends the parent and closes the pipes. A write to them
	// then fails, where it would end this process, and the start goes on. The
	// processes it starts keep the default, since an exec resets a caught signal.
	signal.Notify(make(chan os.Signal, 1), syscall.SIGPIPE)
	return append([]string{args[0]}, args[4:]...)
}

// detachStart runs this start again as a background child with --foreground.
// The child's output reaches the terminal until it holds the lease and prints
// the marker, then the server log does, until the server answers or the child
// exits. stop is the command that stops the server.
func detachStart(l Launch, log, stop string) error {
	executable, err := os.Executable()
	if err != nil {
		return err
	}
	person := "0"
	if runtime.Terminal(os.Stdin) {
		person = "1"
	}
	stdout, childStdout, err := os.Pipe()
	if err != nil {
		return err
	}
	stderr, childStderr, err := os.Pipe()
	if err != nil {
		return err
	}
	// A nil Stdin is the null device, since nothing answers a terminal prompt here.
	command := exec.Command(executable)
	command.Args = append([]string{l.Args[0], childWord, log, person}, append(slices.Clone(l.Args[1:]), "--foreground")...)
	command.Stdout, command.Stderr = childStdout, childStderr
	command.SysProcAttr = detachedProcess()
	err = command.Start()
	childStdout.Close()
	childStderr.Close()
	if err != nil {
		return err
	}
	copied := make(chan struct{})
	go func() {
		io.Copy(os.Stderr, stderr)
		close(copied)
	}()
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
	lines := bufio.NewReader(stdout)
	code := 0
	for {
		line, err := lines.ReadString('\n')
		// A line the child left unfinished ends where the marker starts.
		if before, marked := strings.CutSuffix(line, logMarker); marked {
			os.Stdout.WriteString(before)
			colour := runtime.Terminal(os.Stdout) && os.Getenv("NO_COLOR") == ""
			code = relay(log, exited, os.Stdout, stop, colour, relayPoll)
			break
		}
		os.Stdout.WriteString(line)
		if err != nil {
			code = <-exited
			<-copied
			break
		}
	}
	if code != 0 {
		os.Exit(code)
	}
	return nil
}

// takeLog points a detached child's output at the server log, which it
// empties, and tells the parent to relay that file. It runs once the child
// holds the lease, so a start that loses the race leaves the log of the server
// that won untouched. A link at the log is refused.
func takeLog() error {
	if child == nil {
		return nil
	}
	file, err := state.OpenFile(child.log, os.O_WRONLY|os.O_CREATE|os.O_TRUNC|os.O_APPEND, 0o600)
	if err != nil {
		return err
	}
	// A parent the reader ended reads nothing, and the start goes on.
	os.Stdout.WriteString(logMarker)
	return writeTo(file)
}

// relay copies the log to out line by line and returns the start's exit code: 0 at the ready
// line (not copied), and the server's code if it exits first (after the rest of the log).
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
			if strings.Contains(line, readyMarker) {
				if !blank {
					fmt.Fprintln(out)
				}
				fmt.Fprintf(out, "%s runs in the background. Its log: %s\n\nStop it with:\n\n    %s\n\n", siteName, log, stop)
				return 0
			}
			fmt.Fprint(out, line)
			blank = line == "\n"
		}
		if gone {
			fmt.Fprint(out, pending)
			return code
		}
		time.Sleep(poll)
	}
}

// ownsConsole reports whether a file manager created this process's console: nothing
// but this process holds it, and a detached child's hidden console is not one.
func ownsConsole(detached bool, processes uint32) bool {
	return !detached && processes == 1
}

// shellWord quotes value for the reader's shell. cmd and PowerShell read a
// backslash as itself, and PowerShell reads a quoted first word as a string to
// print, so a Windows path stays bare when nothing else needs quotes.
func shellWord(value string, windows bool) string {
	bare := strings.Trim(value, "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_./:=@%+-") == ""
	if windows {
		bare = strings.Trim(value, "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_./:=@%+-\\") == ""
	}
	switch {
	case bare && value != "":
		return value
	case windows:
		return `"` + value + `"`
	}
	return "'" + strings.ReplaceAll(value, "'", `'\''`) + "'"
}

// stopCommand is the line that stops this start's server in the reader's
// shell. target is the words after stop, with their leading space, "" when
// stop's default finds the server. invoked is the path the reader ran.
func stopCommand(invoked, cwd, target string, windows bool) string {
	word := shellWord(invoked, windows)
	// PowerShell hands a program its full path, where cmd hands over the typed
	// word, and runs a quoted first word only after `&`. Both shells run `.\NAME`.
	if windows && windowsAbsolute.MatchString(invoked) {
		folder := func(path string) string {
			return strings.TrimRight(strings.ReplaceAll(path, "/", `\`), `\`)
		}
		split := strings.LastIndexAny(invoked, `\/`)
		switch {
		case strings.EqualFold(folder(invoked[:split]), folder(cwd)):
			word = `.\` + invoked[split+1:]
		case word[0] == '"':
			word = "& " + word
		}
	}
	return word + " stop" + target
}

// windowsAbsolute matches a path that starts at a drive or a root.
var windowsAbsolute = regexp.MustCompile(`^([A-Za-z]:)?[\\/]`)

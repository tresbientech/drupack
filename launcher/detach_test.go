package main

import (
	"bytes"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"
)

func served(t *testing.T, log string, exited chan int, colour bool) (string, int) {
	t.Helper()
	siteName = "demo"
	path := filepath.Join(t.TempDir(), "server.log")
	if err := os.WriteFile(path, []byte(log), 0o600); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	code := make(chan int, 1)
	go func() {
		got, err := relay(path, exited, &out, "./demo stop", colour, time.Millisecond)
		if err != nil {
			t.Error(err)
		}
		code <- got
	}()
	select {
	case got := <-code:
		return out.String(), got
	case <-time.After(5 * time.Second):
		t.Fatal("the relay did not return")
		return "", 0
	}
}

func TestADetachedChildOwnsNoConsole(t *testing.T) {
	for _, c := range []struct {
		detached  bool
		processes uint32
		want      bool
	}{{false, 1, true}, {false, 2, false}, {true, 1, false}, {true, 2, false}} {
		if got := ownsConsole(c.detached, c.processes); got != c.want {
			t.Fatalf("ownsConsole(%v, %d) = %v, want %v", c.detached, c.processes, got, c.want)
		}
	}
}

func TestRelayStopsAtTheReadyLineAndPrintsTheSummary(t *testing.T) {
	out, code := served(t, "[1/3] Seeding\nStarting the web server.\n\ndemo is ready. Press Ctrl+C to stop.\nlater line\n", make(chan int), false)
	head := "[1/3] Seeding\nStarting the web server.\n\ndemo runs in the background. Its log: "
	tail := "server.log\n\nStop it with:\n\n    ./demo stop\n\n"
	if code != 0 || !strings.HasPrefix(out, head) || !strings.HasSuffix(out, tail) {
		t.Fatalf("code %d, printed %q, want 0 and the log, the summary and the stop command", code, out)
	}
	if strings.Contains(out, "is ready") || strings.Contains(out, "later line") {
		t.Fatalf("printed %q, which carries the ready line or what follows it", out)
	}
}

func TestRelayPrintsTheStopCommandBoldGreenOnATerminal(t *testing.T) {
	out, _ := served(t, "demo is ready.\n", make(chan int), true)
	if !strings.Contains(out, "\x1b[1;32m./demo stop\x1b[0m") {
		t.Fatalf("printed %q, want the stop command in bold green", out)
	}
}

func TestRelayReturnsTheChildsCodeAfterTheRestOfTheLog(t *testing.T) {
	exited := make(chan int, 1)
	exited <- 3
	out, code := served(t, "Another demo start is preparing this Site data\n", exited, false)
	if code != 3 || out != "Another demo start is preparing this Site data\n" {
		t.Fatalf("code %d, printed %q, want 3 and the whole log", code, out)
	}
}

func TestRelayWaitsForTheLogToGrow(t *testing.T) {
	siteName = "demo"
	path := filepath.Join(t.TempDir(), "server.log")
	if err := os.WriteFile(path, []byte("first\nhalf of a li"), 0o600); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	code := make(chan int, 1)
	go func() {
		got, _ := relay(path, make(chan int), &out, "./demo stop", false, time.Millisecond)
		code <- got
	}()
	time.Sleep(50 * time.Millisecond)
	file, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0)
	if err != nil {
		t.Fatal(err)
	}
	file.WriteString("ne\ndemo is ready.\n")
	file.Close()
	select {
	case got := <-code:
		if got != 0 || !strings.HasPrefix(out.String(), "first\nhalf of a line\n") {
			t.Fatalf("code %d, printed %q", got, out.String())
		}
	case <-time.After(5 * time.Second):
		t.Fatal("the relay did not see the appended lines")
	}
}

// An absent log fails the open and a folder fails the read.
func TestRelayNamesAnUnreadableLogAndTheStopCommand(t *testing.T) {
	siteName = "demo"
	folder := t.TempDir()
	for _, log := range []string{filepath.Join(folder, "absent.log"), folder} {
		var out bytes.Buffer
		_, err := relay(log, make(chan int), &out, "./demo stop", true, time.Millisecond)
		if err == nil || !strings.Contains(err.Error(), log) || !strings.HasSuffix(err.Error(), "Stop it with:\n\n    ./demo stop\n") {
			t.Errorf("relay(%s) = %v, want the log's path and the bare stop command", log, err)
		}
		if out.Len() != 0 {
			t.Errorf("relay(%s) printed %q", log, out.String())
		}
	}
}

// The relay matches text runtime/watch/watch.go prints. The two cannot share a constant,
// since the runtime build copies that file alone, so this reads it.
func TestTheRelayMarkerIsStillWhatTheWatcherPrints(t *testing.T) {
	source, err := os.ReadFile(filepath.Join("..", "runtime", "watch", "watch.go"))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(source), readyMarker) {
		t.Fatalf("runtime/watch/watch.go no longer prints %q", readyMarker)
	}
}

func TestTheStopCommandIsTypedForTheReadersShell(t *testing.T) {
	for _, c := range []struct {
		name, invoked, cwd, target string
		windows                    bool
		want                       string
	}{
		{"a bare word", "./demo", "/home/reader", "", false, "./demo stop"},
		{"a target", "./demo", "/home/reader", " --data-dir " + shellWord("my data", false), false, "./demo stop --data-dir 'my data'"},
		{"a quote in a Unix path", "/opt/it's/demo", "/home/reader", "", false, `'/opt/it'\''s/demo' stop`},
		{"a backslash stays bare on Windows", `C:\Tools\demo.exe`, `C:\Users\reader`, "", true, `C:\Tools\demo.exe stop`},
		{"PowerShell runs the executable's folder as .\\", `C:\Users\reader\demo.exe`, `c:/users/reader/`, "", true, `.\demo.exe stop`},
		{"PowerShell runs a quoted path after &", `C:\With Space\demo.exe`, `C:\Users\reader`, "", true, `& "C:\With Space\demo.exe" stop`},
		{"cmd's typed word stays", "demo", `C:\Users\reader`, " " + shellWord(`C:\My Site`, true), true, `demo stop "C:\My Site"`},
	} {
		t.Run(c.name, func(t *testing.T) {
			if got := stopCommand(c.invoked, c.cwd, c.target, c.windows); got != c.want {
				t.Fatalf("stopCommand = %q, want %q", got, c.want)
			}
		})
	}
}

func TestTakeChildReadsTheParentsWordsAndLeavesTheReaders(t *testing.T) {
	t.Cleanup(func() { child = nil })
	for _, line := range [][]string{
		{"./demo", "--data-dir", "d"},
		// The engine serving a folder named detached.
		{"drupack", "detached", "--listen", "127.0.0.1:9000"},
	} {
		if got := takeChild(slices.Clone(line)); !slices.Equal(got, line) || child != nil {
			t.Fatalf("a reader's line became %q, child %v", got, child)
		}
	}
	got := takeChild([]string{"./demo", childWord, "/data/logs/server.log", "1", "--data-dir", "d", "--foreground"})
	if !slices.Equal(got, []string{"./demo", "--data-dir", "d", "--foreground"}) || *child != (detachedChild{log: "/data/logs/server.log", person: true}) {
		t.Fatalf("a child's line became %q, child %+v", got, child)
	}
}

func TestTakeLogRefusesALink(t *testing.T) {
	target := filepath.Join(t.TempDir(), "elsewhere")
	log := filepath.Join(t.TempDir(), "server.log")
	if err := os.Symlink(target, log); err != nil {
		t.Skipf("cannot make a link: %v", err)
	}
	child = &detachedChild{log: log}
	t.Cleanup(func() { child = nil })
	if err := takeLog(); err == nil {
		t.Fatal("takeLog() = nil, want a refusal")
	}
	if _, err := os.Lstat(target); !errors.Is(err, fs.ErrNotExist) {
		t.Errorf("takeLog() created the link's target: %v", err)
	}
}

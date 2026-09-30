package main

import (
	"bytes"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// served writes log, runs relay on it with the exit channel exited, and returns what
// the relay printed and its code.
func served(t *testing.T, log string, exited chan int, colour bool) (string, int) {
	t.Helper()
	siteName = "demo"
	path := filepath.Join(t.TempDir(), "server.log")
	if err := os.WriteFile(path, []byte(log), 0o600); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	code := make(chan int, 1)
	go func() { code <- relay(path, exited, &out, "./demo stop", colour, time.Millisecond) }()
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
		detached  string
		processes uint32
		want      bool
	}{{"", 1, true}, {"", 2, false}, {"1", 1, false}, {"1", 2, false}} {
		if got := ownsConsole(c.detached, c.processes); got != c.want {
			t.Fatalf("ownsConsole(%q, %d) = %v, want %v", c.detached, c.processes, got, c.want)
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

func TestRelayExitsOneAndNamesTheStopCommandOnTheTimeoutLine(t *testing.T) {
	out, code := served(t, "Drupal did not answer at http://localhost:7225/ within 2m0s: boom\n", make(chan int), false)
	if code != 1 || !strings.Contains(out, "Drupal did not answer at") || !strings.Contains(out, "still runs") ||
		!strings.Contains(out, "    ./demo stop\n") {
		t.Fatalf("code %d, printed %q, want 1, the line and the stop command", code, out)
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
	go func() { code <- relay(path, make(chan int), &out, "./demo stop", false, time.Millisecond) }()
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

// The relay matches text runtime/watch/watch.go prints. The two cannot share a constant,
// since the runtime build copies that file alone, so this reads it.
func TestTheRelayMarkersAreStillWhatTheWatcherPrints(t *testing.T) {
	source, err := os.ReadFile(filepath.Join("..", "runtime", "watch", "watch.go"))
	if err != nil {
		t.Fatal(err)
	}
	for _, marker := range []string{readyMarker, timeoutMarker} {
		if !strings.Contains(string(source), marker) {
			t.Fatalf("runtime/watch/watch.go no longer prints %q", marker)
		}
	}
}

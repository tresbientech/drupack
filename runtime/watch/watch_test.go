package main

import (
	"bytes"
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

// exits records each code guard hands its exit function.
func exits() (func(int), <-chan int) {
	codes := make(chan int, 1)
	return func(code int) { codes <- code }, codes
}

func TestGuardWaitsForASignal(t *testing.T) {
	exit, codes := exits()
	stopping := guard(make(chan os.Signal), 10*time.Millisecond, exit)
	select {
	case <-stopping.Done():
		t.Fatal("the stopping context ended with no signal")
	case code := <-codes:
		t.Fatalf("exit(%d) ran with no signal", code)
	case <-time.After(100 * time.Millisecond):
	}
}

func TestGuardStopsAtTheSignalAndExitsAfterTheDeadline(t *testing.T) {
	exit, codes := exits()
	signals := make(chan os.Signal, 1)
	const deadline = 200 * time.Millisecond
	stopping := guard(signals, deadline, exit)
	signalled := time.Now()
	signals <- os.Interrupt
	select {
	case <-stopping.Done():
	case <-time.After(time.Second):
		t.Fatal("the stopping context did not end at the signal")
	}
	select {
	case code := <-codes:
		if elapsed := time.Since(signalled); elapsed < deadline {
			t.Fatalf("exit ran %s after the signal, before the %s deadline", elapsed, deadline)
		}
		if code != 1 {
			t.Fatalf("exit code %d, want 1", code)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("exit never ran after the deadline")
	}
}

// output is a writer run's goroutine and the test read at once.
type output struct {
	mu     sync.Mutex
	buffer bytes.Buffer
}

func (o *output) Write(p []byte) (int, error) {
	o.mu.Lock()
	defer o.mu.Unlock()
	return o.buffer.Write(p)
}

func (o *output) String() string {
	o.mu.Lock()
	defer o.mu.Unlock()
	return o.buffer.String()
}

// scripted answers each request with the next status, repeating the last one,
// and reports when it first answered 204.
func scripted(t *testing.T, statuses ...int) (*httptest.Server, func() time.Time) {
	var mu sync.Mutex
	var readyAt time.Time
	served := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		status := statuses[min(served, len(statuses)-1)]
		served++
		if status == http.StatusNoContent && readyAt.IsZero() {
			readyAt = time.Now()
		}
		mu.Unlock()
		w.WriteHeader(status)
	}))
	t.Cleanup(server.Close)
	return server, func() time.Time {
		mu.Lock()
		defer mu.Unlock()
		return readyAt
	}
}

// watching is one run in flight, with what it printed and opened.
type watching struct {
	out, errs output
	opened    chan string
	stop      context.CancelFunc
	done      chan struct{}
}

// watch starts run on p and ends it when the test ends.
func watch(t *testing.T, p plan) *watching {
	stopping, stop := context.WithCancel(context.Background())
	w := &watching{opened: make(chan string, 10), stop: stop, done: make(chan struct{})}
	go func() {
		run(stopping, "Demo", p, &w.out, &w.errs, func(link string) { w.opened <- link })
		close(w.done)
	}()
	t.Cleanup(func() {
		stop()
		<-w.done
	})
	return w
}

// returns fails the test unless run returns within limit.
func (w *watching) returns(t *testing.T, limit time.Duration) {
	t.Helper()
	select {
	case <-w.done:
	case <-time.After(limit):
		t.Fatalf("run did not return within %s", limit)
	}
}

// probing builds a plan for server with cron far off, which the readiness cases leave unrun.
func probing(server *httptest.Server, open string) plan {
	return plan{
		probe:       server.URL + "/.drupack-id?id=token",
		open:        open,
		cron:        []string{"cron-never-runs"},
		poll:        10 * time.Millisecond,
		readyWithin: 5 * time.Second,
		firstCron:   time.Hour,
		cronEvery:   time.Hour,
	}
}

const readyLine = "\nDemo is ready. Press Ctrl+C to stop.\n"

func TestRunAnnouncesReadyOnceThenOpensTheLink(t *testing.T) {
	server, _ := scripted(t, http.StatusNotFound, http.StatusNotFound, http.StatusNoContent)
	w := watch(t, probing(server, "http://login"))
	select {
	case link := <-w.opened:
		if link != "http://login" {
			t.Fatalf("opened %q, want http://login", link)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("no browser opened")
	}
	if got := w.out.String(); got != readyLine {
		t.Fatalf("printed %q before the browser opened, want the ready line", got)
	}
	time.Sleep(100 * time.Millisecond)
	if got := w.out.String(); got != readyLine {
		t.Fatalf("printed %q, want one ready line", got)
	}
	if len(w.opened) != 0 {
		t.Fatalf("the browser opened %d more times", len(w.opened))
	}
}

func TestRunNamesTheLastAnswerWhenTheSiteNeverAnswers204(t *testing.T) {
	server, _ := scripted(t, http.StatusInternalServerError)
	p := probing(server, "http://login")
	p.readyWithin = 100 * time.Millisecond
	w := watch(t, p)
	w.returns(t, 5*time.Second)
	errs := w.errs.String()
	if !strings.Contains(errs, "Drupal did not answer at "+server.URL+" within") ||
		!strings.Contains(errs, "the server answered 500 Internal Server Error") {
		t.Fatalf("reported %q, want the address and the 500", errs)
	}
	if strings.Contains(errs, "token") {
		t.Fatalf("reported %q, which carries the identity token", errs)
	}
	if got := w.out.String(); got != "" {
		t.Fatalf("printed %q, want no ready line", got)
	}
	if len(w.opened) != 0 {
		t.Fatal("the browser opened for a site that never answered")
	}
}

func TestRunWithNoLinkAnnouncesReadyAndOpensNothing(t *testing.T) {
	server, _ := scripted(t, http.StatusNoContent)
	w := watch(t, probing(server, ""))
	deadline := time.Now().Add(5 * time.Second)
	for w.out.String() == "" && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	time.Sleep(100 * time.Millisecond)
	if got := w.out.String(); got != readyLine {
		t.Fatalf("printed %q, want one ready line", got)
	}
	if len(w.opened) != 0 {
		t.Fatalf("opened %q with no link to open", <-w.opened)
	}
}

// TestHelperProcess is the cron child. It records the time of each run on a line
// of the file it names, then succeeds, fails or hangs.
func TestHelperProcess(t *testing.T) {
	if os.Getenv("DRUPACK_WATCH_HELPER") != "1" {
		return
	}
	arguments := os.Args
	for len(arguments) > 0 && arguments[0] != "--" {
		arguments = arguments[1:]
	}
	mode, record := arguments[1], arguments[2]
	file, err := os.OpenFile(record, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600)
	if err != nil {
		panic(err)
	}
	fmt.Fprintln(file, time.Now().UnixNano())
	file.Close()
	switch mode {
	case "fail":
		fmt.Println("cron failed")
		os.Exit(1)
	case "hang":
		time.Sleep(time.Minute)
	}
	os.Exit(0)
}

// cronning builds a plan whose cron is this test binary in mode, recording its runs
// to a file the returned function reads.
func cronning(t *testing.T, server *httptest.Server, mode string, first, every time.Duration) (plan, func(n int) []time.Time) {
	t.Setenv("DRUPACK_WATCH_HELPER", "1")
	record := filepath.Join(t.TempDir(), "runs")
	p := probing(server, "")
	p.cron = []string{os.Args[0], "-test.run=^TestHelperProcess$", "--", mode, record}
	p.dir = t.TempDir()
	p.firstCron, p.cronEvery = first, every
	return p, func(n int) []time.Time {
		t.Helper()
		deadline := time.Now().Add(10 * time.Second)
		for time.Now().Before(deadline) {
			content, _ := os.ReadFile(record)
			if lines := strings.Fields(string(content)); len(lines) >= n {
				runs := make([]time.Time, len(lines))
				for i, line := range lines {
					nanoseconds, err := strconv.ParseInt(line, 10, 64)
					if err != nil {
						t.Fatal(err)
					}
					runs[i] = time.Unix(0, nanoseconds)
				}
				return runs
			}
			time.Sleep(10 * time.Millisecond)
		}
		t.Fatalf("cron did not run %d times within 10s", n)
		return nil
	}
}

func TestCronRunsAfterItsFirstDelayThenOnItsInterval(t *testing.T) {
	server, readyAt := scripted(t, http.StatusNotFound, http.StatusNotFound, http.StatusNotFound, http.StatusNoContent)
	const first, every = 150 * time.Millisecond, 300 * time.Millisecond
	p, runs := cronning(t, server, "succeed", first, every)
	watch(t, p)
	recorded := runs(2)
	if early := readyAt().Add(first).Sub(recorded[0]); early > 0 {
		t.Fatalf("the first run started %s before its delay after ready", early)
	}
	if gap := recorded[1].Sub(recorded[0]); gap < every {
		t.Fatalf("the second run started %s after the first, before the %s interval", gap, every)
	}
}

func TestCronReportsAFailedRunAndRunsAgain(t *testing.T) {
	server, _ := scripted(t, http.StatusNoContent)
	p, runs := cronning(t, server, "fail", 10*time.Millisecond, 10*time.Millisecond)
	w := watch(t, p)
	runs(2)
	errs := w.errs.String()
	if !strings.Contains(errs, "Scheduled work did not finish: exit status 1\ncron failed") {
		t.Fatalf("reported %q, want the failed run and its output", errs)
	}
}

func TestStoppingEndsACronRunInFlight(t *testing.T) {
	server, _ := scripted(t, http.StatusNoContent)
	p, runs := cronning(t, server, "hang", 10*time.Millisecond, time.Hour)
	w := watch(t, p)
	runs(1)
	w.stop()
	w.returns(t, 5*time.Second)
	if errs := w.errs.String(); errs != "" {
		t.Fatalf("reported %q for a run the stop ended", errs)
	}
}

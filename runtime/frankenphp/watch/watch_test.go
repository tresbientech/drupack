package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"maps"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"slices"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

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

type watching struct {
	out, errs output
	opened    chan string
	shutdowns chan struct{}
	stop      context.CancelFunc
	done      chan struct{}
	// err is what run returned, set before done closes.
	err error
}

func watch(t *testing.T, p plan) *watching {
	stopping, stop := context.WithCancel(context.Background())
	w := &watching{opened: make(chan string, 10), shutdowns: make(chan struct{}, 10), stop: stop, done: make(chan struct{})}
	go func() {
		w.err = run(stopping, "Demo", p, &w.out, &w.errs, func(link string) { w.opened <- link },
			func() { w.shutdowns <- struct{}{} })
		close(w.done)
	}()
	t.Cleanup(func() {
		stop()
		<-w.done
	})
	return w
}

func (w *watching) returns(t *testing.T, limit time.Duration) {
	t.Helper()
	select {
	case <-w.done:
	case <-time.After(limit):
		t.Fatalf("run did not return within %s", limit)
	}
}

func planned(t *testing.T, open string) plan {
	return plan{
		open:       open,
		stopRecord: filepath.Join(t.TempDir(), "stop.json"),
		cron:       []string{"cron-never-runs"},
		firstCron:  time.Hour,
		cronEvery:  time.Hour,
	}
}

const readyLine = "\nDemo is ready. Press Ctrl+C to stop.\n"

func TestRunAnnouncesReadyOnceThenOpensTheLink(t *testing.T) {
	w := watch(t, planned(t, "http://login"))
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

func TestRunReturnsAfterTheReadyLineWhenThePlanHasNoCron(t *testing.T) {
	p := planned(t, "")
	p.cron = nil
	w := watch(t, p)
	w.returns(t, 5*time.Second)
	if got := w.out.String(); got != readyLine {
		t.Fatalf("printed %q, want the ready line", got)
	}
}

func TestRunReturnsTheErrorOfAStopRecordItCannotWrite(t *testing.T) {
	p := planned(t, "http://login")
	p.stopRecord = filepath.Join(t.TempDir(), "missing", "stop.json")
	w := watch(t, p)
	w.returns(t, 5*time.Second)
	if w.err == nil {
		t.Fatal("run returned no error for a stop record it cannot write")
	}
	if got := w.out.String(); got != "" {
		t.Fatalf("printed %q, want no ready line", got)
	}
	if len(w.opened) != 0 {
		t.Fatal("the browser opened for a site nothing can stop")
	}
}

func TestRunWithNoLinkAnnouncesReadyAndOpensNothing(t *testing.T) {
	w := watch(t, planned(t, ""))
	deadline := time.Now().Add(5 * time.Second)
	for w.out.String() == "" && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	time.Sleep(100 * time.Millisecond)
	if got := w.out.String(); got != readyLine {
		t.Fatalf("printed %q, want one ready line", got)
	}
	if len(w.opened) != 0 {
		t.Fatalf("opened %q with no page to open", <-w.opened)
	}
}

func (w *watching) stopChannel(t *testing.T, p plan) stopRecord {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for !strings.Contains(w.out.String(), "is ready") && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	content, err := os.ReadFile(p.stopRecord)
	if err != nil {
		t.Fatalf("no stop record once the site was ready: %v", err)
	}
	var record stopRecord
	if err := json.Unmarshal(content, &record); err != nil {
		t.Fatal(err)
	}
	return record
}

func request(t *testing.T, record stopRecord, method, path, authorization string) int {
	t.Helper()
	req, err := http.NewRequest(method, fmt.Sprintf("http://127.0.0.1:%d%s", record.Port, path), nil)
	if err != nil {
		t.Fatal(err)
	}
	if authorization != "" {
		req.Header.Set("Authorization", authorization)
	}
	response, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	return response.StatusCode
}

func TestStopRecordHoldsThePortTheTokenAndThePidOwnerOnly(t *testing.T) {
	p := planned(t, "")
	w := watch(t, p)
	record := w.stopChannel(t, p)
	if record.Port == 0 || record.Pid != os.Getpid() || len(record.Token) != 64 {
		t.Fatalf("record %+v, want a port, this pid and 64 hex characters", record)
	}
	if runtime.GOOS != "windows" {
		info, err := os.Stat(p.stopRecord)
		if err != nil {
			t.Fatal(err)
		}
		if mode := info.Mode().Perm(); mode != 0o600 {
			t.Fatalf("record mode %o, want 600", mode)
		}
	}
}

func recordKeys(t *testing.T, path string) []string {
	t.Helper()
	content, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var record map[string]any
	if err := json.Unmarshal(content, &record); err != nil {
		t.Fatal(err)
	}
	return slices.Sorted(maps.Keys(record))
}

// launcher/internal/state/state_test.go reads the fixture with the launcher's stop
// client, so a key renamed on either side fails one of the two.
func TestStopRecordCarriesTheKeysOfItsFixture(t *testing.T) {
	path := filepath.Join(t.TempDir(), "stop.json")
	stopping, cancel := context.WithCancel(context.Background())
	defer cancel()
	if err := serveStop(stopping, path, func() {}); err != nil {
		t.Fatal(err)
	}
	if written, fixture := recordKeys(t, path), recordKeys(t, filepath.Join("testdata", "stop.json")); !slices.Equal(written, fixture) {
		t.Fatalf("serveStop writes the keys %q, the fixture holds %q", written, fixture)
	}
}

func TestStopChannelAnswersOnLoopbackAlone(t *testing.T) {
	addresses, err := net.InterfaceAddrs()
	if err != nil {
		t.Fatal(err)
	}
	other := ""
	for _, address := range addresses {
		if network, ok := address.(*net.IPNet); ok && network.IP.To4() != nil && !network.IP.IsLoopback() {
			other = network.IP.String()
			break
		}
	}
	if other == "" {
		t.Skip("this computer has no address but loopback")
	}
	path := filepath.Join(t.TempDir(), "stop.json")
	if err := serveStop(t.Context(), path, func() {}); err != nil {
		t.Fatal(err)
	}
	content, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var record stopRecord
	if err := json.Unmarshal(content, &record); err != nil {
		t.Fatal(err)
	}
	port := strconv.Itoa(record.Port)
	conn, err := net.DialTimeout("tcp", net.JoinHostPort("127.0.0.1", port), time.Second)
	if err != nil {
		t.Fatalf("loopback: %v", err)
	}
	conn.Close()
	if conn, err := net.DialTimeout("tcp", net.JoinHostPort(other, port), time.Second); err == nil {
		conn.Close()
		t.Fatalf("the stop channel answers on %s", other)
	}
}

func TestStopWithTheTokenAnswers204ThenShutsDown(t *testing.T) {
	p := planned(t, "")
	w := watch(t, p)
	record := w.stopChannel(t, p)
	if status := request(t, record, http.MethodPost, "/stop", "Bearer "+record.Token); status != http.StatusNoContent {
		t.Fatalf("status %d, want 204", status)
	}
	select {
	case <-w.shutdowns:
	case <-time.After(5 * time.Second):
		t.Fatal("the shutdown callback never ran")
	}
}

func TestStopWithAWrongOrMissingTokenAnswers403AndStopsNothing(t *testing.T) {
	p := planned(t, "")
	w := watch(t, p)
	record := w.stopChannel(t, p)
	for _, authorization := range []string{"", "Bearer " + strings.Repeat("0", 64), record.Token, "Bearer"} {
		if status := request(t, record, http.MethodPost, "/stop", authorization); status != http.StatusForbidden {
			t.Fatalf("authorization %q: status %d, want 403", authorization, status)
		}
	}
	if len(w.shutdowns) != 0 {
		t.Fatal("the shutdown callback ran without the token")
	}
}

func TestStopChannelAnswers404ToAnythingButPostStop(t *testing.T) {
	p := planned(t, "")
	w := watch(t, p)
	record := w.stopChannel(t, p)
	bearer := "Bearer " + record.Token
	for _, tried := range []struct{ method, path string }{
		{http.MethodGet, "/stop"}, {http.MethodPut, "/stop"}, {http.MethodPost, "/"}, {http.MethodPost, "/stop/now"},
	} {
		if status := request(t, record, tried.method, tried.path, bearer); status != http.StatusNotFound {
			t.Fatalf("%s %s: status %d, want 404", tried.method, tried.path, status)
		}
	}
	if len(w.shutdowns) != 0 {
		t.Fatal("the shutdown callback ran for a request that was not POST /stop")
	}
}

func TestStopChannelClosesWhenStoppingEnds(t *testing.T) {
	p := planned(t, "")
	w := watch(t, p)
	record := w.stopChannel(t, p)
	w.stop()
	w.returns(t, 5*time.Second)
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		connection, err := net.Dial("tcp", fmt.Sprintf("127.0.0.1:%d", record.Port))
		if err != nil {
			return
		}
		connection.Close()
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatal("the stop channel still accepted a connection after stopping ended")
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

func cronning(t *testing.T, mode string, first, every time.Duration) (plan, func(n int) []time.Time) {
	t.Setenv("DRUPACK_WATCH_HELPER", "1")
	record := filepath.Join(t.TempDir(), "runs")
	p := planned(t, "")
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
	const first, every = 150 * time.Millisecond, 300 * time.Millisecond
	p, runs := cronning(t, "succeed", first, every)
	started := time.Now()
	watch(t, p)
	recorded := runs(2)
	if early := started.Add(first).Sub(recorded[0]); early > 0 {
		t.Fatalf("the first run started %s before its delay", early)
	}
	if gap := recorded[1].Sub(recorded[0]); gap < every {
		t.Fatalf("the second run started %s after the first, before the %s interval", gap, every)
	}
}

func TestCronReportsAFailedRunAndRunsAgain(t *testing.T) {
	p, runs := cronning(t, "fail", 10*time.Millisecond, 10*time.Millisecond)
	w := watch(t, p)
	runs(2)
	errs := w.errs.String()
	if !strings.Contains(errs, "Scheduled work did not finish: exit status 1\ncron failed") {
		t.Fatalf("reported %q, want the failed run and its output", errs)
	}
}

func TestStoppingEndsACronRunInFlight(t *testing.T) {
	p, runs := cronning(t, "hang", 10*time.Millisecond, time.Hour)
	w := watch(t, p)
	runs(1)
	w.stop()
	w.returns(t, 5*time.Second)
	if errs := w.errs.String(); errs != "" {
		t.Fatalf("reported %q for a run the stop ended", errs)
	}
}

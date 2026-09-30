// The work that runs beside the server for its whole life. The builds copy this file
// into FrankenPHP's main package next to runtime/entrypoint.go, so it imports the
// standard library alone and names nothing from FrankenPHP.
package main

import (
	"context"
	"crypto/rand"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"time"
)

// plan holds what run watches: where readiness answers, what to open once ready,
// and how and when cron runs.
type plan struct {
	probe       string   // the identity route's URL, token included
	open        string   // the login link to open once ready, "" for none
	cron        []string // one cron run's argv
	dir         string   // the directory cron runs in
	stopRecord  string   // the file that tells SITE stop where the stop channel listens
	poll        time.Duration
	readyWithin time.Duration
	firstCron   time.Duration
	cronEvery   time.Duration
}

// run waits for the site to answer, opens the stop channel, prints the ready line on
// out, calls opener with p.open when it is set, then runs cron on p's schedule.
// shutdown stops the process when a request to the channel carries the token. run
// returns once stopping ends and any cron child in flight has exited, or when the
// site never answers.
func run(stopping context.Context, site string, p plan, out, errs io.Writer, opener func(string), shutdown func()) {
	if !ready(stopping, p, errs) {
		return
	}
	// The record precedes the ready line, so a start that has returned is always stoppable.
	if err := serveStop(stopping, p.stopRecord, shutdown); err != nil {
		panic(err)
	}
	fmt.Fprintf(out, "\n%s is ready. Press Ctrl+C to stop.\n", site)
	if p.open != "" {
		opener(p.open)
	}
	scheduleCron(stopping, p, errs)
}

// stopRecord is what SITE stop reads from the file serveStop writes.
type stopRecord struct {
	Port  int    `json:"port"`
	Token string `json:"token"`
	Pid   int    `json:"pid"`
}

// serveStop listens for one request, POST /stop with the token as a bearer value, and
// writes the record that names the port and the token to path. The channel binds
// loopback whatever address the site listens on, so no other computer reaches it, and
// the record is owner-only, so no other account on this one holds the token. A valid
// request gets 204, then shutdown runs. Ending stopping closes the listener.
func serveStop(stopping context.Context, path string, shutdown func()) error {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return err
	}
	raw := make([]byte, 32)
	if _, err := rand.Read(raw); err != nil {
		return err
	}
	token := hex.EncodeToString(raw)
	content, err := json.Marshal(stopRecord{Port: listener.Addr().(*net.TCPAddr).Port, Token: token, Pid: os.Getpid()})
	if err != nil {
		return err
	}
	if err := writeOwnerOnly(path, content); err != nil {
		return err
	}
	wanted := []byte("Bearer " + token)
	server := http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost || r.URL.Path != "/stop" {
			http.NotFound(w, r)
			return
		}
		if subtle.ConstantTimeCompare([]byte(r.Header.Get("Authorization")), wanted) != 1 {
			w.WriteHeader(http.StatusForbidden)
			return
		}
		w.WriteHeader(http.StatusNoContent)
		w.(http.Flusher).Flush()
		shutdown()
	})}
	go server.Serve(listener)
	go func() {
		<-stopping.Done()
		server.Close()
	}()
	return nil
}

// writeOwnerOnly replaces the file at path with content, in one rename, so a reader
// sees the old record or the new one and never half of either. CreateTemp makes the
// file mode 0600.
func writeOwnerOnly(path string, content []byte) error {
	staging, err := os.CreateTemp(filepath.Dir(path), filepath.Base(path)+".*")
	if err != nil {
		return err
	}
	_, err = staging.Write(content)
	if closed := staging.Close(); err == nil {
		err = closed
	}
	if err == nil {
		err = os.Rename(staging.Name(), path)
	}
	if err != nil {
		os.Remove(staging.Name())
	}
	return err
}

// ready polls p.probe until it answers 204. The open link is a one-time login
// link, which a request spends, so the poll asks the identity route instead.
//
// Only 204 counts. A 500 from a failed bootstrap and a 400 from a rejected host
// both reach a client that connects, so a poll accepting any answer would
// announce a site nobody can use.
func ready(stopping context.Context, p plan, errs io.Writer) bool {
	// A cold start answers its first request slowly, and a request that never returns would
	// otherwise hold the poll past the deadline.
	client := http.Client{Timeout: 30 * time.Second}
	started := time.Now()
	deadline := started.Add(p.readyWithin)
	var last error
	for time.Now().Before(deadline) {
		response, err := client.Get(p.probe)
		if err == nil {
			response.Body.Close()
			if response.StatusCode == http.StatusNoContent {
				return true
			}
			err = fmt.Errorf("the server answered %s", response.Status)
		}
		last = err
		select {
		case <-time.After(p.poll):
		case <-stopping.Done():
			return false
		}
	}
	// The line names the site's address, without the identity token the probe carries.
	address, _ := url.Parse(p.probe)
	address.Path, address.RawQuery = "/", ""
	// The site keeps serving, so this names what the wait saw rather than stopping anything.
	fmt.Fprintf(errs, "Drupal did not answer at %s within %s: %v\n",
		address, time.Since(started).Round(time.Second), last)
	return false
}

// scheduleCron runs Drupal's cron in a child process p.firstCron after ready, then
// every p.cronEvery. automated_cron runs the same work inside whichever request
// arrives after its interval elapses, where a fetch that cannot reach drupal.org,
// or a queue that takes a minute, holds that request's PHP thread and the database
// rows behind it. Ending stopping ends a child in flight, so a run cannot hold the
// process open.
func scheduleCron(stopping context.Context, p plan, errs io.Writer) {
	timer := time.NewTimer(p.firstCron)
	defer timer.Stop()
	for {
		select {
		case <-timer.C:
		case <-stopping.Done():
			return
		}
		cron := exec.CommandContext(stopping, p.cron[0], p.cron[1:]...)
		cron.Dir = p.dir
		// A failed run is reported and the next one still runs: cron carries
		// search indexing and queue work, which a later run picks up.
		if output, err := cron.CombinedOutput(); err != nil && stopping.Err() == nil {
			fmt.Fprintf(errs, "Scheduled work did not finish: %v\n%s", err, output)
		}
		timer.Reset(p.cronEvery)
	}
}

// guard returns a context that ends at the first signal on signals, so work the
// process started outside a request stops. Caddy finishes an in-flight PHP request
// before it exits, and a request has no upper bound, so exit runs with 1 once
// deadline passes after that signal; a healthy stop ends the process first.
func guard(signals <-chan os.Signal, deadline time.Duration, exit func(int)) context.Context {
	stopping, stop := context.WithCancel(context.Background())
	go func() {
		<-signals
		stop()
		time.Sleep(deadline)
		exit(1)
	}()
	return stopping
}

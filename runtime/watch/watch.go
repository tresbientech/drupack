// The work that runs beside the server for its whole life. The builds copy this file
// into FrankenPHP's main package next to runtime/entrypoint.go, so it imports the
// standard library alone and names nothing from FrankenPHP.
package main

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"time"
)

// plan is what run watches: where readiness answers, what to open once ready,
// and how and when cron runs.
type plan struct {
	probe       string   // the identity route's URL, token included
	open        string   // the login link to open once ready, "" for none
	cron        []string // one cron run's argv
	dir         string   // the directory cron runs in
	poll        time.Duration
	readyWithin time.Duration
	firstCron   time.Duration
	cronEvery   time.Duration
}

// run waits for the site to answer, prints the ready line on out, calls opener with
// p.open when it is set, then runs cron on p's schedule. It returns once stopping
// ends and any cron child in flight has exited, or when the site never answers.
func run(stopping context.Context, site string, p plan, out, errs io.Writer, opener func(string)) {
	if !ready(stopping, p, errs) {
		return
	}
	fmt.Fprintf(out, "\n%s is ready. Press Ctrl+C to stop.\n", site)
	if p.open != "" {
		opener(p.open)
	}
	scheduleCron(stopping, p, errs)
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

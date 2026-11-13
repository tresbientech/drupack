// The work that runs beside the server for its whole life. The builds copy this file
// into FrankenPHP's main package next to runtime/frankenphp/entrypoint.go, so it imports the
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
	"os"
	"os/exec"
	"path/filepath"
	"time"
)

type plan struct {
	open       string   // the page to open once ready, "" for none
	cron       []string // one cron run's argv, empty for a server that runs no cron
	dir        string   // the directory cron runs in
	stopRecord string   // the file that tells SITE stop where the stop channel listens
	firstCron  time.Duration
	cronEvery  time.Duration
}

// run opens the stop channel, announces the site and runs cron. The caller has
// loaded the server's configuration, so the site already answers. shutdown stops
// the process when a request to the stop channel carries the token. run returns
// the error of a stop channel it cannot open, at once after the ready line when
// p has no cron, and otherwise once stopping ends and any cron child in flight
// has exited.
func run(stopping context.Context, site string, p plan, out, errs io.Writer, opener func(string), shutdown func()) error {
	// The record precedes the ready line, so a start that has returned is always stoppable.
	if err := serveStop(stopping, p.stopRecord, shutdown); err != nil {
		return err
	}
	fmt.Fprintf(out, "\n%s is ready. Press Ctrl+C to stop.\n", site)
	if p.open != "" {
		opener(p.open)
	}
	if len(p.cron) > 0 {
		scheduleCron(stopping, p, errs)
	}
	return nil
}

type stopRecord struct {
	Port  int    `json:"port"`
	Token string `json:"token"`
	Pid   int    `json:"pid"`
}

// serveStop accepts POST /stop with the token as a bearer value and writes the
// port and token to path. The channel binds loopback whatever address the site
// listens on, so no other computer reaches it. On Unix the record is owner-only;
// Windows ignores the mode, and the file takes the access of its directory.
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
	server := http.Server{
		Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
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
		}),
		// A connection that never finishes its headers is dropped, so a local
		// process cannot hold the channel's connections open.
		ReadHeaderTimeout: 10 * time.Second,
	}
	go func() { _ = server.Serve(listener) }()
	go func() {
		<-stopping.Done()
		server.Close()
	}()
	return nil
}

// One rename, so a reader sees the old record or the new one and never half of
// either. CreateTemp makes the file mode 0600 on Unix.
func writeOwnerOnly(path string, content []byte) error {
	staging, err := os.CreateTemp(filepath.Dir(path), filepath.Base(path)+".*")
	if err != nil {
		return err
	}
	_, err = staging.Write(content)
	if err == nil {
		err = staging.Sync()
	}
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

// automated_cron runs the same work inside whichever request arrives after its
// interval elapses, where a fetch that cannot reach drupal.org, or a slow queue,
// holds that request's PHP thread and the database rows behind it. Ending
// stopping ends a child in flight, so a run cannot hold the process open.
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

// Caddy finishes an in-flight PHP request before it exits, and a request has no
// upper bound, so exit runs with 1 once deadline passes after the first signal.
// A healthy stop ends the process first.
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

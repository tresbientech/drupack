// The work that runs beside the server for its whole life. The builds copy this file
// into FrankenPHP's main package next to runtime/entrypoint.go, so it imports the
// standard library alone and names nothing from FrankenPHP.
package main

import (
	"context"
	"os"
	"time"
)

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

package main

import (
	"os"
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

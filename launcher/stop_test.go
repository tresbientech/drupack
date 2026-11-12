package main

import (
	"bytes"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"testing"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state/statetest"
)

// serving writes a stop record in dir for a channel that answers status, and
// runs answered once it has.
func serving(t *testing.T, dir string, status int, answered func()) {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(status)
		answered()
	}))
	t.Cleanup(server.Close)
	address, err := url.Parse(server.URL)
	if err != nil {
		t.Fatal(err)
	}
	record := fmt.Sprintf(`{"port":%s,"token":"token","pid":1}`, address.Port())
	if err := os.WriteFile(filepath.Join(dir, "stop.json"), []byte(record), 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestStop(t *testing.T) {
	packedAs(t, "fixture-site", "dev", false)
	for _, row := range []struct {
		name string
		// arrange prepares dir and returns how long stop waits.
		arrange func(t *testing.T, dir string) time.Duration
		out     string
		err     string
	}{
		{"no lease file", func(*testing.T, string) time.Duration { return time.Second }, "fixture-site is not running.\n", ""},
		{"a free lease beside a stale record", func(t *testing.T, dir string) time.Duration {
			release := statetest.HoldLease(t, dir)
			release()
			serving(t, dir, http.StatusNoContent, func() {})
			return time.Second
		}, "fixture-site is not running.\n", ""},
		{"a held lease with no record", func(t *testing.T, dir string) time.Duration {
			statetest.HoldLease(t, dir)
			return time.Second
		}, "", "A fixture-site start is still preparing this folder: NAMED"},
		{"a refused request", func(t *testing.T, dir string) time.Duration {
			statetest.HoldLease(t, dir)
			serving(t, dir, http.StatusForbidden, func() {})
			return time.Second
		}, "", "The stop channel of fixture-site refused the request (HTTP/1.1 403 Forbidden): NAMED"},
		{"a server that keeps the lease", func(t *testing.T, dir string) time.Duration {
			statetest.HoldLease(t, dir)
			serving(t, dir, http.StatusNoContent, func() {})
			return 0
		}, "", "fixture-site did not stop within 0 seconds: NAMED"},
		{"a server that stops", func(t *testing.T, dir string) time.Duration {
			serving(t, dir, http.StatusNoContent, statetest.HoldLease(t, dir))
			return 5 * time.Second
		}, "fixture-site stopped.\n", ""},
	} {
		t.Run(row.name, func(t *testing.T) {
			dir := t.TempDir()
			wait := row.arrange(t, dir)
			var out bytes.Buffer
			err := stop("fixture-site", state.At(dir), "folder", "NAMED", wait, &out)
			message := ""
			if err != nil {
				message = err.Error()
			}
			if out.String() != row.out || message != row.err {
				t.Errorf("stop() printed %q and returned %q, want %q and %q", out.String(), message, row.out, row.err)
			}
		})
	}
}

func TestStopOnMissingSiteDataCreatesNothing(t *testing.T) {
	packedAs(t, "fixture-site", "dev", false)
	missing := filepath.Join(t.TempDir(), "never-made")
	var out bytes.Buffer
	if err := runStop(Launch{Name: "fixture-site", Root: t.TempDir()}, command{word: "stop", target: missing}, &out); err != nil || out.String() != "fixture-site is not running.\n" {
		t.Errorf("runStop() = %q, %v", out.String(), err)
	}
	if _, err := os.Stat(missing); !os.IsNotExist(err) {
		t.Errorf("stop created %s", missing)
	}
}

func TestProjectFolder(t *testing.T) {
	packedAs(t, "fixture-site", "dev", true)
	root := t.TempDir()
	project := filepath.Join(root, "a", "b")
	if err := os.MkdirAll(project, 0o700); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(root, "link")
	if err := os.Symlink(project, link); err != nil {
		t.Skip("this account cannot create a symbolic link")
	}
	file := filepath.Join(root, "file")
	if err := os.WriteFile(file, nil, 0o600); err != nil {
		t.Fatal(err)
	}
	resolved, err := filepath.EvalSymlinks(filepath.Join(root, "a"))
	if err != nil {
		t.Fatal(err)
	}
	// realpath resolves the link before it reads `..`, so `link/..` names the link target's parent.
	if got, err := projectFolder("fixture-site", link+string(filepath.Separator)+".."); err != nil || got != runtime.Canonical(resolved) {
		t.Errorf("projectFolder(link/..) = %q, %v, want %q", got, err, runtime.Canonical(resolved))
	}
	for _, written := range []string{filepath.Join(root, "missing"), file} {
		want := "No such directory: " + written + ". Run fixture-site --help."
		if _, err := projectFolder("fixture-site", written); err == nil || err.Error() != want {
			t.Errorf("projectFolder(%q) = %v, want %q", written, err, want)
		}
	}
}

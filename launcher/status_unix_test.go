//go:build unix

package main

import (
	"bytes"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/fsguard"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state/statetest"
)

// fakeRuntime writes a shell script that stands in for the runtime, which the Drush call runs.
func fakeRuntime(t *testing.T, body string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "frankenphp")
	if err := os.WriteFile(path, []byte("#!/bin/sh\n"+body+"\n"), 0o700); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestDrushStatus(t *testing.T) {
	for _, row := range []struct {
		name, runtime string
		drush         bool
		wait          time.Duration
		out, err      string
	}{
		{"Drush answers from the project", `echo "$PWD $*"`, true, 3 * time.Second, "PROJECT php-cli /cache/app/drush status --no-ansi\n", ""},
		{"Drush fails", "echo 'first line' >&2; echo 'second line' >&2; exit 2", true, 3 * time.Second, "", "exit 2: first line"},
		{"Drush gives no answer in time", "exec /bin/sleep 30", true, 2 * time.Second, "", "no answer within 2 seconds"},
		{"the project has no Drush", "exit 0", false, 3 * time.Second, "", "PROJECT has no Drush: PROJECT/vendor/drush/drush/drush.php does not exist. Run composer require drush/drush in it."},
	} {
		t.Run(row.name, func(t *testing.T) {
			f := newProject(t, `{}`)
			if row.drush {
				put(t, filepath.Join(f.project, "vendor", "drush", "drush", "drush.php"), "<?php")
			}
			l := f.launch()
			l.Entry = fakeRuntime(t, row.runtime)
			out, err := drushStatus(l, command{word: "status"}, f.project, row.wait)
			message := ""
			if err != nil {
				message = err.Error()
			}
			project := fsguard.Canonical(f.project)
			if want := strings.ReplaceAll(row.out, "PROJECT", project); out != want {
				t.Errorf("drushStatus() = %q, want %q", out, want)
			}
			if want := strings.ReplaceAll(row.err, "PROJECT", project); message != want {
				t.Errorf("drushStatus() error = %q, want %q", message, want)
			}
		})
	}
}

func TestStatusKeepsExitZeroWhenDrushFails(t *testing.T) {
	f := newProject(t, `{}`)
	put(t, filepath.Join(f.project, "vendor", "drush", "drush", "drush.php"), "<?php")
	dir := state.FolderEntry(f.root, fsguard.Canonical(f.project), false)
	if err := os.MkdirAll(dir.Path(), 0o700); err != nil {
		t.Fatal(err)
	}
	statetest.HoldLease(t, dir.Path())
	serving(t, dir.Path(), http.StatusNoContent, func() {})
	if err := dir.WriteListener(state.Listener{Listen: "127.0.0.1:3787", Host: "localhost", Scheme: "http"}); err != nil {
		t.Fatal(err)
	}
	l := f.launch()
	l.Entry = fakeRuntime(t, "echo 'SQLSTATE: no database' >&2; exit 1")
	var out bytes.Buffer
	if err := runStatus(l, command{word: "status", target: f.project}, &out); err != nil {
		t.Fatalf("runStatus() = %v, want exit 0", err)
	}
	if !strings.HasSuffix(out.String(), "\nDrush status unavailable: exit 1: SQLSTATE: no database\n") {
		t.Errorf("runStatus() printed %q", out.String())
	}
}

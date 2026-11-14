package main

import (
	"bytes"
	"net/http"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/fsguard"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state/statetest"
)

func TestStatus(t *testing.T) {
	packedAs(t, "fixture-site", "dev", false)
	// answering is a server whose start wrote the listener and, when given, start.json.
	answering := func(record *state.Start) func(t *testing.T, dir string) {
		return func(t *testing.T, dir string) {
			statetest.HoldLease(t, dir)
			serving(t, dir, http.StatusNoContent, func() {})
			if err := state.At(dir).WriteListener(state.Listener{Listen: "127.0.0.1:7225", Host: "localhost", Scheme: "http", StaticListen: "127.0.0.1:7226"}); err != nil {
				t.Fatal(err)
			}
			if record != nil {
				if err := state.At(dir).WriteStart(*record); err != nil {
					t.Fatal(err)
				}
			}
		}
	}
	rows := "\n\n  URL:    http://localhost:7225\n  Static: http://localhost:7226/\n"
	for _, row := range []struct {
		name    string
		arrange func(t *testing.T, dir string)
		out     string
		code    int
	}{
		{"no lease", func(*testing.T, string) {}, "fixture-site is not running.\n", 3},
		{"a free lease beside a stale stop record", func(t *testing.T, dir string) {
			statetest.HoldLease(t, dir)()
			serving(t, dir, http.StatusNoContent, func() {})
		}, "fixture-site is not running.\n", 3},
		{"a held lease with no stop record", func(t *testing.T, dir string) { statetest.HoldLease(t, dir) },
			"A fixture-site start is still preparing this Site data: DIR\n", 4},
		{"a server an earlier release started", answering(nil), "fixture-site serves DIR." + rows, 0},
		{"a background server", answering(&state.Start{Cwd: "/srv", Words: []string{"--data-dir", "a dir", "--access-log"}, Background: true}),
			"fixture-site serves DIR in the background." + rows + "  Logs:   DIR/logs/\n  From:   /srv\n  Words:  fixture-site --data-dir 'a dir' --access-log\n", 0},
		{"a foreground server", answering(&state.Start{Cwd: "/srv", Words: []string{"--foreground"}}),
			"fixture-site serves DIR in the foreground." + rows + "  From:   /srv\n  Words:  fixture-site --foreground\n", 0},
	} {
		t.Run(row.name, func(t *testing.T) {
			dir := t.TempDir()
			row.arrange(t, dir)
			var out bytes.Buffer
			err := runStatus(Launch{Name: "fixture-site", Root: t.TempDir()}, command{word: "status", target: dir}, &out)
			code := 0
			if exit, ok := err.(exitError); ok {
				code = int(exit)
			} else if err != nil {
				t.Fatal(err)
			}
			want := bytes.ReplaceAll([]byte(row.out), []byte("DIR"), []byte(fsguard.Canonical(dir)))
			// This Launch names no release, so a server's status ends on Drush's refusal.
			got, refused := out.String(), false
			if row.code == 0 {
				got, _, refused = strings.Cut(got, "\nDrush status unavailable: ")
			}
			if got != string(want) || code != row.code || refused != (row.code == 0) {
				t.Errorf("runStatus() printed %q and exited %d, want %q and %d", out.String(), code, want, row.code)
			}
		})
	}
}

func TestStatusOnMissingSiteDataIsNotRunning(t *testing.T) {
	packedAs(t, "fixture-site", "dev", false)
	missing := filepath.Join(t.TempDir(), "never-made")
	var out bytes.Buffer
	if err := runStatus(Launch{Name: "fixture-site", Root: t.TempDir()}, command{word: "status", target: missing}, &out); err != exitError(3) || out.String() != "fixture-site is not running.\n" {
		t.Errorf("runStatus() = %q, %v", out.String(), err)
	}
	if _, err := os.Stat(missing); !os.IsNotExist(err) {
		t.Errorf("status created %s", missing)
	}
}

func TestStartRecord(t *testing.T) {
	child := &detachedChild{log: "server.log"}
	for _, row := range []struct {
		name string
		l    Launch
		want state.Start
	}{
		{"a detached child drops the --foreground its parent appended", Launch{Args: []string{"drupack", "site", "--listen", "127.0.0.1:9000", "--foreground"}, Engine: true, Child: child},
			state.Start{Cwd: "/srv", Words: []string{"site", "--listen", "127.0.0.1:9000"}, Background: true}},
		{"a foreground start keeps its --foreground", Launch{Args: []string{"drupack", "--foreground"}, Engine: true},
			state.Start{Cwd: "/srv", Words: []string{"--foreground"}}},
		{"a site's secrets stay out", Launch{Args: []string{"SITE", "--db-password", "p", "--admin-password=a", "--foreground"}, Child: child},
			state.Start{Cwd: "/srv", Words: nil, Background: true}},
	} {
		got := startRecord(row.l, "/srv")
		if got.Cwd != row.want.Cwd || got.Background != row.want.Background || !slices.Equal(got.Words, row.want.Words) {
			t.Errorf("%s: startRecord() = %+v, want %+v", row.name, got, row.want)
		}
	}
}

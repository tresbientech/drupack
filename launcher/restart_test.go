package main

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

func TestRestartRefuses(t *testing.T) {
	missing := filepath.Join(t.TempDir(), "never-made")
	for _, row := range []struct {
		name   string
		record *state.Start
		target string
		want   string
	}{
		{"a Site data with no record", nil, "", "No start of this Site data is recorded: DIR. Start it with fixture-site start."},
		{"a Site data that does not exist", nil, missing, "No start of this Site data is recorded: " + missing + ". Start it with fixture-site start."},
		{"a foreground record", &state.Start{Cwd: "/srv", Words: []string{"--foreground"}},
			"", "fixture-site started this Site data in the foreground: DIR. Restart it where it runs, in its terminal or its service manager."},
	} {
		t.Run(row.name, func(t *testing.T) {
			dir := t.TempDir()
			if row.record != nil {
				if err := state.At(dir).WriteStart(*row.record); err != nil {
					t.Fatal(err)
				}
			}
			target := dir
			if row.target != "" {
				target = row.target
			}
			err := restart(Launch{Name: "fixture-site", Root: t.TempDir()}, command{word: "restart", target: target})
			want := strings.ReplaceAll(row.want, "DIR", resolvedDir(t, dir))
			if err == nil || err.Error() != want {
				t.Errorf("restart() = %v, want %q", err, want)
			}
		})
	}
}

func TestReplay(t *testing.T) {
	cwd, err := os.Getwd()
	if err != nil {
		t.Fatal(err)
	}
	for _, row := range []struct {
		name   string
		l      Launch
		words  []string
		named  string
		args   []string
		target string
		access string
	}{
		{"a site's relative argv0 and --data-dir give way, and its log stays relative",
			Launch{Args: []string{"./bin/SITE", "restart"}, ConsoleOwned: true}, []string{"start", "--access-log=a.log", "--data-dir", "old"}, "/srv/data",
			[]string{filepath.Join(cwd, "bin/SITE"), "start", "--access-log=a.log", "--data-dir=/srv/data"}, "", "a.log"},
		{"a site's Site data beats DRUPACK_DATA_DIR",
			Launch{Args: []string{"SITE", "restart"}, Parent: []string{"DRUPACK_DATA_DIR=env"}}, []string{"--no-browser"}, "/srv/data",
			[]string{"SITE", "--no-browser", "--data-dir=/srv/data"}, "", ""},
		{"a site's bare start", Launch{Args: []string{"SITE", "restart"}}, nil, "/srv/data", []string{"SITE", "--data-dir=/srv/data"}, "", ""},
		{"the engine's folder becomes the absolute one",
			Launch{Args: []string{"drupack", "restart"}, Engine: true}, []string{"sub", "--access-log=a.log"}, "/srv/project",
			[]string{"drupack", "sub", "--access-log=a.log"}, "/srv/project", "a.log"},
		{"the engine's start word", Launch{Args: []string{"drupack", "restart"}, Engine: true}, []string{"start"}, "/srv/project",
			[]string{"drupack", "start"}, "/srv/project", ""},
	} {
		l, c, err := replay(row.l, state.Start{Cwd: "/srv", Words: row.words, Background: true}, row.named)
		if err != nil {
			t.Fatalf("%s: %v", row.name, err)
		}
		access := ""
		if c.options.AccessLog != nil {
			access = *c.options.AccessLog
		}
		// The rows write paths with forward slashes, and replay prints them in the platform's form.
		args := slices.Clone(row.args)
		for i := range args {
			args[i] = filepath.FromSlash(args[i])
		}
		wantData := ""
		if !row.l.Engine {
			wantData = filepath.FromSlash(row.named)
		}
		if !slices.Equal(l.Args, args) || l.ConsoleOwned || c.word != "start" || c.target != filepath.FromSlash(row.target) || c.options.DataDir != wantData || access != row.access {
			t.Errorf("%s: replay() = %q, %+v, want %q", row.name, l.Args, c, args)
		}
	}
}

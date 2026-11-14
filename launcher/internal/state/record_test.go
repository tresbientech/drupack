package state_test

import (
	"errors"
	"os"
	"path/filepath"
	"slices"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

func write(t *testing.T, dir, name, content string) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestListener(t *testing.T) {
	const unreadable = "an UnreadableError"
	for _, row := range []struct {
		name   string
		record string
		want   state.Listener
		ok     bool
		err    string
	}{
		{"a whole record", `{"listen":"127.0.0.1:7225","host":"demo.drupack.site","files-dir":"/files","scheme":"https","static-listen":"127.0.0.1:7226"}`,
			state.Listener{Listen: "127.0.0.1:7225", Host: "demo.drupack.site", FilesDir: "/files", Scheme: "https", StaticListen: "127.0.0.1:7226"}, true, ""},
		{"nulls for what the start named none of", `{"listen":"[::1]:80","host":"::1","files-dir":null,"scheme":"http","static-listen":null}`,
			state.Listener{Listen: "[::1]:80", Host: "::1", Scheme: "http"}, true, ""},
		{"no scheme", `{"listen":"127.0.0.1:80","host":"localhost"}`, state.Listener{}, false, "Recorded listener has no scheme"},
		{"no record", "", state.Listener{}, false, ""},
		{"no JSON object", "listen", state.Listener{}, false, unreadable},
		{"no listen address", `{"host":"localhost"}`, state.Listener{}, false, "Recorded listener has no listen address"},
		{"no host", `{"listen":"127.0.0.1:80"}`, state.Listener{}, false, "Recorded listener has no host"},
	} {
		dir := t.TempDir()
		if row.record != "" {
			write(t, dir, "listener", row.record)
		}
		got, ok, err := state.At(dir).Listener()
		message := ""
		if err != nil {
			message = err.Error()
		}
		if row.err == unreadable {
			var bad *state.UnreadableError
			if !errors.As(err, &bad) || bad.Path != filepath.Join(dir, "listener") {
				t.Errorf("%s: error = %v, want an UnreadableError", row.name, err)
			}
			continue
		}
		if got != row.want || ok != row.ok || message != row.err {
			t.Errorf("%s: Listener() = %+v, %v, %q; want %+v, %v, %q", row.name, got, ok, message, row.want, row.ok, row.err)
		}
	}
}

func TestStartRecord(t *testing.T) {
	dir := state.At(t.TempDir())
	if _, ok, err := dir.Start(); ok || err != nil {
		t.Fatalf("Start() on an empty directory = %v, %v, want no record", ok, err)
	}
	want := state.Start{Cwd: "/srv", Words: []string{"--listen", "127.0.0.1:9000", "site"}, Background: true}
	if err := dir.WriteStart(want); err != nil {
		t.Fatal(err)
	}
	got, ok, err := dir.Start()
	if !ok || err != nil || got.Cwd != want.Cwd || got.Background != want.Background || !slices.Equal(got.Words, want.Words) {
		t.Errorf("Start() = %+v, %v, %v, want %+v", got, ok, err, want)
	}
	write(t, dir.Path(), "start.json", "words")
	if _, _, err := dir.Start(); err == nil || err.Error() != "Cannot read the recorded start: "+filepath.Join(dir.Path(), "start.json") {
		t.Errorf("Start() on a damaged record = %v", err)
	}
}

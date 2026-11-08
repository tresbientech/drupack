package state_test

import (
	"errors"
	"os"
	"path/filepath"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

func write(t *testing.T, dir, name, content string) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(dir, name), []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
}

// The records are the ones a release up to the Go start wrote through PHP.
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
		{"a record up to 1.0.0-beta8 served HTTP", `{"listen":"127.0.0.1:80","host":"localhost"}`,
			state.Listener{Listen: "127.0.0.1:80", Host: "localhost", Scheme: "http"}, true, ""},
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

func TestBundleAndMercure(t *testing.T) {
	dir := t.TempDir()
	d := state.At(dir)
	if _, ok := d.Bundle(); ok {
		t.Error("Bundle() found one in an empty directory")
	}
	write(t, dir, "ca-bundle.pem", "roots")
	if path, ok := d.Bundle(); !ok || path != filepath.Join(dir, "ca-bundle.pem") {
		t.Errorf("Bundle() = %q, %v", path, ok)
	}

	write(t, dir, "mercure_publisher_secret", "pub")
	if _, _, ok, err := d.MercureSecrets(); ok || err != nil {
		t.Errorf("one secret: MercureSecrets() ok = %v, err = %v; want neither", ok, err)
	}
	write(t, dir, "mercure_subscriber_secret", "sub")
	if publisher, subscriber, ok, err := d.MercureSecrets(); publisher != "pub" || subscriber != "sub" || !ok || err != nil {
		t.Errorf("MercureSecrets() = %q, %q, %v, %v", publisher, subscriber, ok, err)
	}
}

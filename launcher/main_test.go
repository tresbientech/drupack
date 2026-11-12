package main

import (
	"os"
	"path/filepath"
	"slices"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

func TestTakeConsoleStripsTheLeadingWordAlone(t *testing.T) {
	got, owned := takeConsole([]string{"./demo", consoleWord, "--data-dir", "d"})
	if !owned || !slices.Equal(got, []string{"./demo", "--data-dir", "d"}) {
		t.Fatalf("a console line became %q, %v", got, owned)
	}
	line := []string{"./demo", "--data-dir", "d", consoleWord}
	if got, owned := takeConsole(line); owned || !slices.Equal(got, line) {
		t.Fatalf("a later word became %q, %v", got, owned)
	}
}

func TestNodeRefusal(t *testing.T) {
	without := newSite(t, siteconfig.Site{Port: 1})
	if err, want := nodeRefusal(without.launch(), "node"), "Unknown command: node\n\n"+siteHelpText("fixture-site"); err == nil || err.Error() != want {
		t.Errorf("a site without Node: %v", err)
	}
	with := newSite(t, siteconfig.Site{Port: 1, Node: siteconfig.NodeLTS})
	for _, word := range []string{"node", "npm", "npx"} {
		want := word + ": this musl build of demo carries no Node. Run the glibc build, the file without -musl in its name."
		if err := nodeRefusal(Launch{Application: with.application, Name: "demo"}, word); err == nil || err.Error() != want {
			t.Errorf("%s on a musl build: %v, want %q", word, err, want)
		}
	}
}

func TestTLSDirMakesACallersRelativeFolderAbsolute(t *testing.T) {
	got, err := tlsDir(Launch{Parent: []string{"DRUPACK_TLS_DIR=" + filepath.Join("relative", "tls")}})
	if err != nil {
		t.Fatal(err)
	}
	directory, err := os.Getwd()
	if err != nil {
		t.Fatal(err)
	}
	if want := filepath.Join(directory, "relative", "tls"); got != want {
		t.Errorf("tlsDir() = %q, want %q", got, want)
	}
}

func TestTLSDirKeepsAnEmptyValueEmpty(t *testing.T) {
	if got, err := tlsDir(Launch{Parent: []string{"DRUPACK_TLS_DIR="}}); err != nil || got != "" {
		t.Errorf("tlsDir() = %q, %v, want empty", got, err)
	}
}

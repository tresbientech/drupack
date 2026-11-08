package main

import (
	"os"
	"path/filepath"
	"slices"
	"testing"
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

func TestTLSDirMakesACallersRelativeFolderAbsolute(t *testing.T) {
	t.Setenv("DRUPACK_TLS_DIR", filepath.Join("relative", "tls"))
	got, err := tlsDir()
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
	t.Setenv("DRUPACK_TLS_DIR", "")
	if got, err := tlsDir(); err != nil || got != "" {
		t.Errorf("tlsDir() = %q, %v, want empty", got, err)
	}
}

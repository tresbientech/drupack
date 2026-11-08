package main

import (
	"os"
	"path/filepath"
	"testing"
)

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

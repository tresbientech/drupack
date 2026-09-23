package main

import (
	"flag"
	"os"
	"path/filepath"
	"testing"
)

func TestRefusedRequestLeavesNoWorkDirectory(t *testing.T) {
	temporary := t.TempDir()
	t.Setenv("TMPDIR", temporary)
	site := t.TempDir()
	if err := os.WriteFile(filepath.Join(site, "drupack.yml"),
		[]byte("name: acme\nrecipe: recipes/acme\nsite_name: Acme\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	flag.CommandLine = flag.NewFlagSet("drupack-build", flag.ContinueOnError)
	os.Args = []string{"drupack-build", "--site", site, "--libc", "uclibc", "--engine", "/engine",
		"--engine-version", "1.0.0", "--php", "/php", "--composer", "/composer.phar"}

	if err := run(); err == nil {
		t.Fatal("run accepted --libc uclibc")
	}
	entries, err := os.ReadDir(temporary)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 0 {
		t.Fatalf("the refused build left %v in TMPDIR", entries[0].Name())
	}
}

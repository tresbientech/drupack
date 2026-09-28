package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/build"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// buildRuntime packs a directory holding one entry file and returns what
// writeBuildCopy takes.
func buildRuntime(t *testing.T) ([]byte, runtime.Manifest) {
	t.Helper()
	directory := t.TempDir()
	if err := os.WriteFile(filepath.Join(directory, "drupack"), []byte("entry"), 0700); err != nil {
		t.Fatal(err)
	}
	payload, manifest, err := runtime.Build(directory, "1.0.0", "drupack")
	if err != nil {
		t.Fatalf("Build: %v", err)
	}
	return payload, manifest
}

// source stands in for the launcher source tree writeBuildCopy copies.
func source(t *testing.T) string {
	t.Helper()
	directory := t.TempDir()
	if err := os.WriteFile(filepath.Join(directory, "payload.go"), []byte("package main\n"), 0600); err != nil {
		t.Fatal(err)
	}
	return directory
}

func TestWriteBuildCopyCarriesTheRuntime(t *testing.T) {
	build := t.TempDir()
	payload, manifest := buildRuntime(t)
	if err := writeBuildCopy(build, source(t), payload, manifest, site{name: "acme", version: "1.4.0"}); err != nil {
		t.Fatalf("writeBuildCopy: %v", err)
	}
	if carried := readFile(t, filepath.Join(build, "runtime.tar.zst")); carried != string(payload) {
		t.Fatal("runtime.tar.zst differs from the packed payload")
	}
	var m runtime.Manifest
	if err := json.Unmarshal([]byte(readFile(t, filepath.Join(build, "manifest.json"))), &m); err != nil {
		t.Fatalf("manifest.json: %v", err)
	}
	if m.Entry != "drupack" {
		t.Fatalf("manifest.json names entry %q", m.Entry)
	}
	generated := readFile(t, filepath.Join(build, "payload.go"))
	for _, want := range []string{
		"//go:embed runtime.tar.zst\nvar runtimePayload []byte",
		"//go:embed manifest.json\nvar runtimeManifest []byte",
		"var siteName = \"acme\"",
		"var siteVersion = \"1.4.0\"",
		"var engine = false",
	} {
		if !strings.Contains(generated, want) {
			t.Fatalf("payload.go does not carry %q:\n%s", want, generated)
		}
	}
}

func readFile(t *testing.T, path string) string {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return string(data)
}

func TestTheEngineExecutableCarriesTheEngineMark(t *testing.T) {
	generated := embeddedPayloadSource(site{name: build.EngineName, version: "0.5.0", engine: true})
	for _, want := range []string{"var siteName = \"drupack\"", "var siteVersion = \"0.5.0\"", "var engine = true"} {
		if !strings.Contains(generated, want) {
			t.Fatalf("payload.go does not carry %q:\n%s", want, generated)
		}
	}
}

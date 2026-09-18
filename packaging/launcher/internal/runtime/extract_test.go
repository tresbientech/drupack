package runtime_test

import (
	"strings"
	"testing"

	runtimepkg "git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

func TestExtractRefusesUndeclaredEntry(t *testing.T) {
	payload, manifest := buildFixture(t)

	// Files[0] is "bin/app" (the entry); dropping the rest still leaves
	// "lib/data.txt" in the payload, but no longer declared.
	trimmed := runtimepkg.Manifest{
		Version: manifest.Version,
		Entry:   manifest.Entry,
		Files:   manifest.Files[:1],
	}

	destination := t.TempDir()
	err := runtimepkg.Extract(destination, payload, trimmed)
	if err == nil {
		t.Fatal("expected an error for an undeclared payload entry")
	}
	if !strings.Contains(err.Error(), "undeclared") {
		t.Fatalf("expected an undeclared-entry error, got: %v", err)
	}
}

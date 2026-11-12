package cache_test

import (
	"io"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/payload"
)

func TestExtractRefusesUndeclaredEntry(t *testing.T) {
	packed, manifest := buildFixture(t)

	// Files[0] is "bin/app" (the entry); dropping the rest still leaves
	// "lib/data.txt" in the payload, but no longer declared.
	trimmed := payload.Manifest{
		Version: manifest.Version,
		Entry:   manifest.Entry,
		Files:   manifest.Files[:1],
	}

	destination := t.TempDir()
	err := payload.Extract(destination, packed, trimmed, io.Discard)
	if err == nil {
		t.Fatal("expected an error for an undeclared payload entry")
	}
	if !strings.Contains(err.Error(), "undeclared") {
		t.Fatalf("expected an undeclared-entry error, got: %v", err)
	}
}

//go:build unix

package runtime_test

// Each of these cases builds its state out of permission bits: a mode of 0500 or 0750
// says what the case needs it to say only where the kernel enforces it. Windows enforces
// none of it: root_windows.go reads no mode bits and names one cache root with no
// temporary fallback, and a directory mode there never stops lock_windows.go from
// creating its lock file.

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	runtimepkg "git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

func TestRootFallsBackToTempDirWhenTheCacheDirRefusesWrites(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("root ignores directory mode bits, so a 0500 home would not refuse the write")
	}

	t.Setenv("DRUPACK_CACHE_DIR", "")
	t.Setenv("XDG_CACHE_HOME", "")
	home := t.TempDir()
	if err := os.Chmod(home, 0500); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.Chmod(home, 0700) })
	t.Setenv("HOME", home)

	root, err := runtimepkg.Root()
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(root, os.TempDir()) {
		t.Fatalf("expected a root under %q, got %q", os.TempDir(), root)
	}
}

func TestRootRejectsAGroupWritableCacheDir(t *testing.T) {
	root := t.TempDir()
	if err := os.Chmod(root, 0750); err != nil {
		t.Fatal(err)
	}
	t.Setenv("DRUPACK_CACHE_DIR", root)

	if _, err := runtimepkg.Root(); err == nil {
		t.Fatal("expected a group-writable cache directory to be rejected")
	}
}

func TestPrepareLockFailureFallsBackToActiveEntry(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("root ignores directory mode bits, so a 0500 root would not refuse the write")
	}

	payload, installed := buildFixture(t)
	root := t.TempDir()

	// Install the active entry by hand, the way stage and writeActive would,
	// without ever opening root's lock file.
	key := runtimepkg.Key(installed.Version, payload)
	entry := filepath.Join(root, key)
	if err := os.MkdirAll(entry, 0700); err != nil {
		t.Fatal(err)
	}
	if err := runtimepkg.Extract(entry, payload, installed); err != nil {
		t.Fatal(err)
	}
	contents, err := json.Marshal(installed)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(entry, runtimepkg.ManifestName), contents, 0600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, "active"), []byte(key+"\n"), 0600); err != nil {
		t.Fatal(err)
	}

	// root has never had a lock file, so removing root's own write bit makes
	// creating one fail, the way a caller that cannot take the lock would see.
	if err := os.Chmod(root, 0500); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.Chmod(root, 0700) })

	broken := installed
	broken.Version = "2.0.0"
	var notice bytes.Buffer
	got, err := runtimepkg.Prepare(root, payload, broken, &notice)
	if err != nil {
		t.Fatal(err)
	}
	if got != entry {
		t.Fatalf("fallback returned %q, want the active entry %q", got, entry)
	}
	if !strings.Contains(notice.String(), "Using the runtime already in the cache.") {
		t.Fatalf("notice did not explain the fallback: %q", notice.String())
	}
}

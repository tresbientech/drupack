//go:build unix

package runtime_test

// Each of these cases builds its state out of permission bits: a mode of 0500 or 0750
// says what the case needs it to say only where the kernel enforces it. Windows enforces
// none of it: root_windows.go reads no mode bits and names one cache root with no
// temporary fallback, and a directory mode there never stops lock_windows.go from
// creating its lock file.

import (
	"encoding/json"
	"fmt"
	"io"
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

	root, err := runtimepkg.Root("acme", io.Discard)
	if err != nil {
		t.Fatal(err)
	}
	want := filepath.Join(os.TempDir(), fmt.Sprintf("acme-%d", os.Getuid()), "runtime")
	if root != want {
		t.Fatalf("expected the root %q, got %q", want, root)
	}
}

func TestRootIsNamedAfterTheSite(t *testing.T) {
	t.Setenv("DRUPACK_CACHE_DIR", "")
	t.Setenv("XDG_CACHE_HOME", "")
	t.Setenv("HOME", t.TempDir())
	cache, err := os.UserCacheDir()
	if err != nil {
		t.Fatal(err)
	}

	roots := map[string]string{}
	for _, name := range []string{"alpha", "beta"} {
		root, err := runtimepkg.Root(name, io.Discard)
		if err != nil {
			t.Fatal(err)
		}
		if want := filepath.Join(cache, name, "runtime"); root != want {
			t.Fatalf("Root(%q) = %q; want %q", name, root, want)
		}
		roots[name] = root
	}
	if roots["alpha"] == roots["beta"] {
		t.Fatalf("two sites share the root %q", roots["alpha"])
	}
}

func TestRootRejectsAGroupWritableCacheDir(t *testing.T) {
	root := t.TempDir()
	if err := os.Chmod(root, 0750); err != nil {
		t.Fatal(err)
	}
	t.Setenv("DRUPACK_CACHE_DIR", root)

	if _, err := runtimepkg.Root("acme", io.Discard); err == nil {
		t.Fatal("expected a group-writable cache directory to be rejected")
	}
}

func TestPrepareLockFailureStopsAndKeepsTheActiveEntry(t *testing.T) {
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
	if err := runtimepkg.Extract(entry, payload, installed, io.Discard); err != nil {
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
	_, err = runtimepkg.Prepare(root, payload, broken, io.Discard)
	if err == nil {
		t.Fatal("a cache this start cannot lock returned no error")
	}
	if !strings.Contains(err.Error(), "could not lock the runtime cache") {
		t.Fatalf("error did not name the lock failure: %v", err)
	}
	if _, statErr := os.Stat(filepath.Join(entry, "bin", "app")); statErr != nil {
		t.Fatalf("the active entry did not survive the lock failure: %v", statErr)
	}
}

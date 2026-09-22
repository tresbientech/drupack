package runtime_test

import (
	"bytes"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	runtimepkg "git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

const fixtureEntryContent = "entry executable content"

// buildFixture packs a two-file runtime directory and returns its payload
// and manifest, ready for Prepare or Extract.
func buildFixture(t *testing.T) ([]byte, runtimepkg.Manifest) {
	t.Helper()
	source := t.TempDir()
	if err := os.MkdirAll(filepath.Join(source, "bin"), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(source, "lib"), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(source, "bin", "app"), []byte(fixtureEntryContent), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(source, "lib", "data.txt"), []byte("library data"), 0600); err != nil {
		t.Fatal(err)
	}
	payload, manifest, err := runtimepkg.Build(source, "1.0.0", "bin/app")
	if err != nil {
		t.Fatal(err)
	}
	return payload, manifest
}

func TestPrepareWarmStartReturnsEntryWithoutNotice(t *testing.T) {
	payload, manifest := buildFixture(t)
	root := t.TempDir()

	var first bytes.Buffer
	entry, err := runtimepkg.Prepare(root, payload, manifest, &first)
	if err != nil {
		t.Fatal(err)
	}
	if first.Len() == 0 {
		t.Fatal("expected a notice on the first stage")
	}

	var second bytes.Buffer
	again, err := runtimepkg.Prepare(root, payload, manifest, &second)
	if err != nil {
		t.Fatal(err)
	}
	if again != entry {
		t.Fatalf("warm start returned %q, want %q", again, entry)
	}
	if second.Len() != 0 {
		t.Fatalf("warm start wrote to the notice writer: %q", second.String())
	}
}

func TestPrepareRestagesWhenFileSizeChanges(t *testing.T) {
	payload, manifest := buildFixture(t)
	root := t.TempDir()

	entry, err := runtimepkg.Prepare(root, payload, manifest, io.Discard)
	if err != nil {
		t.Fatal(err)
	}

	appPath := filepath.Join(entry, "bin", "app")
	if err := os.WriteFile(appPath, []byte("corrupted"), 0700); err != nil {
		t.Fatal(err)
	}

	again, err := runtimepkg.Prepare(root, payload, manifest, io.Discard)
	if err != nil {
		t.Fatal(err)
	}

	restored, err := os.ReadFile(filepath.Join(again, "bin", "app"))
	if err != nil {
		t.Fatal(err)
	}
	if string(restored) != fixtureEntryContent {
		t.Fatalf("file was not restored: %q", restored)
	}
	// The re-stage claims its own name, so the corrupted entry is never written
	// over, and a third start settles on the entry the second one activated.
	third, err := runtimepkg.Prepare(root, payload, manifest, io.Discard)
	if err != nil {
		t.Fatal(err)
	}
	if third != again {
		t.Fatalf("a later start returned %q, want the activated entry %q", third, again)
	}
}

func TestPrepareConcurrentCallsAgreeAndLeaveNoStaging(t *testing.T) {
	payload, manifest := buildFixture(t)
	root := t.TempDir()

	const callers = 2
	var wg sync.WaitGroup
	results := make([]string, callers)
	errs := make([]error, callers)
	start := make(chan struct{})
	for i := 0; i < callers; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			<-start
			results[i], errs[i] = runtimepkg.Prepare(root, payload, manifest, io.Discard)
		}(i)
	}
	close(start)
	wg.Wait()

	for _, err := range errs {
		if err != nil {
			t.Fatal(err)
		}
	}
	for i := 1; i < callers; i++ {
		if results[i] != results[0] {
			t.Fatalf("goroutines disagreed on the directory: %q vs %q", results[0], results[i])
		}
	}

	entries, err := os.ReadDir(root)
	if err != nil {
		t.Fatal(err)
	}
	for _, e := range entries {
		if strings.Contains(e.Name(), ".staging-") {
			t.Fatalf("leftover staging directory: %s", e.Name())
		}
	}
}

func TestPrepareChecksumMismatchLeavesTheActiveEntryUntouched(t *testing.T) {
	payload, manifest := buildFixture(t)
	root := t.TempDir()

	entry, err := runtimepkg.Prepare(root, payload, manifest, io.Discard)
	if err != nil {
		t.Fatal(err)
	}
	appPath := filepath.Join(entry, "bin", "app")
	original, err := os.ReadFile(appPath)
	if err != nil {
		t.Fatal(err)
	}

	tampered := manifest
	tampered.Files = append([]runtimepkg.File(nil), manifest.Files...)
	tampered.Files[0].SHA256 = "0000000000000000000000000000000000000000000000000000000000000000"

	if _, err := runtimepkg.Prepare(root, payload, tampered, io.Discard); err == nil {
		t.Fatal("a checksum mismatch returned no error")
	}
	active, err := os.ReadFile(filepath.Join(root, "active"))
	if err != nil {
		t.Fatal(err)
	}
	if strings.TrimSpace(string(active)) != filepath.Base(entry) {
		t.Fatalf("active names %q after a failed restage, want %q",
			strings.TrimSpace(string(active)), filepath.Base(entry))
	}

	unchanged, err := os.ReadFile(appPath)
	if err != nil {
		t.Fatal(err)
	}
	if string(unchanged) != string(original) {
		t.Fatal("the existing entry changed after a checksum mismatch")
	}
}

func TestCleanupKeepsContentTheCacheRootAlreadyHeld(t *testing.T) {
	payload, manifest := buildFixture(t)
	// DRUPACK_CACHE_DIR can name a directory the reader already uses.
	root := t.TempDir()
	keepFile := filepath.Join(root, "holiday-photos.tar")
	if err := os.WriteFile(keepFile, []byte("not ours"), 0600); err != nil {
		t.Fatal(err)
	}
	keepDir := filepath.Join(root, "someone-elses-project")
	if err := os.MkdirAll(filepath.Join(keepDir, "src"), 0700); err != nil {
		t.Fatal(err)
	}

	first := manifest
	first.Version = "1.0.0"
	if _, err := runtimepkg.Prepare(root, payload, first, io.Discard); err != nil {
		t.Fatal(err)
	}
	second := manifest
	second.Version = "2.0.0"
	if _, err := runtimepkg.Prepare(root, payload, second, io.Discard); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(keepFile); err != nil {
		t.Fatalf("cleanup removed a file the cache root already held: %v", err)
	}
	if _, err := os.Stat(filepath.Join(keepDir, "src")); err != nil {
		t.Fatalf("cleanup removed a directory the cache root already held: %v", err)
	}
	firstEntry := filepath.Join(root, runtimepkg.Key(first.Version, payload))
	if _, err := os.Stat(firstEntry); !os.IsNotExist(err) {
		t.Fatal("cleanup kept the first version's entry")
	}
}

func TestCleanupRemovesAnAbandonedStagingDirectory(t *testing.T) {
	payload, manifest := buildFixture(t)
	root := t.TempDir()
	abandoned := filepath.Join(root, "9.9.9-abcdef012345.staging-777")
	if err := os.MkdirAll(abandoned, 0700); err != nil {
		t.Fatal(err)
	}

	if _, err := runtimepkg.Prepare(root, payload, manifest, io.Discard); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(abandoned); !os.IsNotExist(err) {
		t.Fatal("an abandoned staging directory survived a successful start")
	}
}

func TestParseManifestRejectsDotDot(t *testing.T) {
	data := []byte(`{"version":"1.0.0","entry":"../escape","files":[{"path":"../escape","size":1,"sha256":"aa"}]}`)
	if _, err := runtimepkg.ParseManifest(data); err == nil {
		t.Fatal("expected an error for a path containing ..")
	}
}

func TestParseManifestRejectsAbsolutePath(t *testing.T) {
	data := []byte(`{"version":"1.0.0","entry":"/etc/passwd","files":[{"path":"/etc/passwd","size":1,"sha256":"aa"}]}`)
	if _, err := runtimepkg.ParseManifest(data); err == nil {
		t.Fatal("expected an error for an absolute path")
	}
}

func TestParseManifestRejectsUnsafeVersions(t *testing.T) {
	for _, version := range []string{"", ".", "..", "sub/dir", "/absolute"} {
		data := []byte(`{"version":"` + version + `","entry":"drupack","files":[{"path":"drupack","size":1,"sha256":"aa"}]}`)
		if _, err := runtimepkg.ParseManifest(data); err == nil {
			t.Fatalf("expected an error for the version %q", version)
		}
	}
}

func TestPrepareSecondVersionRemovesFirstVersionEntry(t *testing.T) {
	payload, first := buildFixture(t)
	root := t.TempDir()

	firstEntry, err := runtimepkg.Prepare(root, payload, first, io.Discard)
	if err != nil {
		t.Fatal(err)
	}

	// Same payload, a second version: only the key changes, as a real upgrade would.
	second := first
	second.Version = "2.0.0"
	secondEntry, err := runtimepkg.Prepare(root, payload, second, io.Discard)
	if err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(firstEntry); !os.IsNotExist(err) {
		t.Fatalf("the first version's entry survived activating the second: %v", err)
	}
	active, err := os.ReadFile(filepath.Join(root, "active"))
	if err != nil {
		t.Fatal(err)
	}
	if strings.TrimSpace(string(active)) != filepath.Base(secondEntry) {
		t.Fatalf("active names %q, want %q", strings.TrimSpace(string(active)), filepath.Base(secondEntry))
	}
}

// A release whose runtime does not unpack cannot run on the release already in the
// cache: the application beside it belongs to the release that failed.
func TestPrepareRefusesAPayloadThatDoesNotDecodeAndKeepsTheActiveEntry(t *testing.T) {
	payload, installed := buildFixture(t)
	root := t.TempDir()

	activeEntry, err := runtimepkg.Prepare(root, payload, installed, io.Discard)
	if err != nil {
		t.Fatal(err)
	}

	broken := installed
	broken.Version = "2.0.0"
	if _, err := runtimepkg.Prepare(root, []byte("not a zstd frame"), broken, io.Discard); err == nil {
		t.Fatal("a payload that does not decode returned no error")
	}
	if _, err := os.Stat(filepath.Join(activeEntry, "bin", "app")); err != nil {
		t.Fatalf("the active entry did not survive the failed stage: %v", err)
	}
	active, err := os.ReadFile(filepath.Join(root, "active"))
	if err != nil {
		t.Fatal(err)
	}
	if strings.TrimSpace(string(active)) != filepath.Base(activeEntry) {
		t.Fatalf("active names %q, want %q", strings.TrimSpace(string(active)), filepath.Base(activeEntry))
	}
}

func TestPrepareReportsCacheRootAndByteCountWhenNoActiveEntryExists(t *testing.T) {
	_, manifest := buildFixture(t)
	root := t.TempDir()

	var total int64
	for _, file := range manifest.Files {
		total += file.Size
	}

	if _, err := runtimepkg.Prepare(root, []byte("not a zstd frame"), manifest, io.Discard); err == nil {
		t.Fatal("expected an error when no active entry exists to fall back to")
	} else if want := fmt.Sprintf("could not unpack the runtime into %s, which needs %d bytes", root, total); !strings.Contains(err.Error(), want) {
		t.Fatalf("error %q does not contain %q", err.Error(), want)
	}
}

func TestPrepareRefusesAnUnsafeActiveFile(t *testing.T) {
	_, manifest := buildFixture(t)
	root := t.TempDir()
	if err := os.WriteFile(filepath.Join(root, "active"), []byte("../escape"), 0600); err != nil {
		t.Fatal(err)
	}

	_, err := runtimepkg.Prepare(root, []byte("not a zstd frame"), manifest, io.Discard)
	if err == nil {
		t.Fatal("expected the staging error since active names an unsafe entry")
	}
	if !strings.Contains(err.Error(), "could not unpack the runtime into") {
		t.Fatalf("expected the staging error, got %q", err.Error())
	}
	if _, statErr := os.Stat(filepath.Join(root, "..", "escape")); !os.IsNotExist(statErr) {
		t.Fatalf("an entry named escape appeared outside root: %v", statErr)
	}
}

func TestRootPrefersTheCacheDirOverTheDefaultRoot(t *testing.T) {
	base := t.TempDir()
	// Root creates the named directory itself, at the private mode privateRoot demands.
	cache := filepath.Join(base, "cache")
	xdg := filepath.Join(base, "xdg")
	t.Setenv("DRUPACK_CACHE_DIR", cache)
	t.Setenv("XDG_CACHE_HOME", xdg)

	root, err := runtimepkg.Root(io.Discard)
	if err != nil {
		t.Fatal(err)
	}
	// A prefix comparison breaks on a Windows account whose name is non-ASCII:
	// asciiRoot resolves cache to its 8.3 short name there, which does not share
	// cache's spelling. os.SameFile identifies the same directory either way.
	cacheInfo, err := os.Stat(cache)
	if err != nil {
		t.Fatal(err)
	}
	rootInfo, err := os.Stat(root)
	if err != nil {
		t.Fatal(err)
	}
	if !os.SameFile(cacheInfo, rootInfo) {
		t.Fatalf("expected root %q to be the same directory as %q", root, cache)
	}
	if _, err := os.Stat(filepath.Join(xdg, "Drupack")); !os.IsNotExist(err) {
		t.Fatalf("the default root under %q gained an entry", xdg)
	}
}

func TestSafePathRefusesAWindowsVolumeName(t *testing.T) {
	// filepath.VolumeName is a no-op on unix, so this only refuses on
	// windows, where it parses the drive letter and the UNC share.
	for _, path := range []string{"C:evil", `\\server\share`} {
		clean := filepath.Clean(filepath.FromSlash(path))
		if filepath.VolumeName(clean) != "" && runtimepkg.SafePath(path) {
			t.Fatalf("SafePath(%q) accepted a path naming a volume", path)
		}
	}
}

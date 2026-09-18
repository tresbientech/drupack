package runtime_test

import (
	"bytes"
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
	if again != entry {
		t.Fatalf("restage returned %q, want %q", again, entry)
	}

	restored, err := os.ReadFile(appPath)
	if err != nil {
		t.Fatal(err)
	}
	if string(restored) != fixtureEntryContent {
		t.Fatalf("file was not restored: %q", restored)
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

func TestPrepareChecksumMismatchLeavesEntryUntouched(t *testing.T) {
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
		t.Fatal("expected a checksum mismatch error")
	}

	unchanged, err := os.ReadFile(appPath)
	if err != nil {
		t.Fatal(err)
	}
	if string(unchanged) != string(original) {
		t.Fatal("the existing entry changed after a checksum mismatch")
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

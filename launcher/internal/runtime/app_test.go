package runtime

import (
	"bytes"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/klauspost/compress/zstd"
)

// TestProgressReportsEachStep reads a payload in pieces and checks that the
// reports arrive as the reader advances, not all at the end.
func TestProgressReportsEachStep(t *testing.T) {
	notice := &bytes.Buffer{}
	total := 100 * megabyte
	p := &progress{total: int64(total), notice: notice}
	reader := p.reading(bytes.NewReader(make([]byte, total)))

	buffer := make([]byte, megabyte)
	for {
		_, err := reader.Read(buffer)
		if err == io.EOF {
			break
		}
		if err != nil {
			t.Fatalf("read failed: %v", err)
		}
	}
	p.last()

	reports := strings.Split(strings.TrimSuffix(notice.String(), "\n"), "\n")
	if len(reports) != progressReports {
		t.Fatalf("wrote %d reports, wanted %d: %q", len(reports), progressReports, reports)
	}
	if got, want := reports[0], "  10 of 100 MB"; got != want {
		t.Errorf("first report is %q, wanted %q", got, want)
	}
	if got, want := reports[len(reports)-1], "  100 of 100 MB"; got != want {
		t.Errorf("last report is %q, wanted %q", got, want)
	}
}

// TestProgressLastNamesTheTotal covers a payload whose final read falls short
// of a step, where the closing report still has to name the whole payload.
func TestProgressLastNamesTheTotal(t *testing.T) {
	notice := &bytes.Buffer{}
	total := 15 * megabyte
	p := &progress{total: int64(total), notice: notice}
	if _, err := io.Copy(io.Discard, p.reading(bytes.NewReader(make([]byte, total)))); err != nil {
		t.Fatalf("copy failed: %v", err)
	}
	p.last()

	reports := strings.Split(strings.TrimSuffix(notice.String(), "\n"), "\n")
	if got, want := reports[len(reports)-1], "  15 of 15 MB"; got != want {
		t.Errorf("last report is %q, wanted %q", got, want)
	}
}

// unpackedApp builds an application directory with the two markers a finished
// unpacking leaves, dated used days ago.
func unpackedApp(t *testing.T, appRoot, name string, used time.Duration) string {
	t.Helper()
	path := filepath.Join(appRoot, name)
	if err := os.MkdirAll(path, 0700); err != nil {
		t.Fatalf("could not build %s: %v", path, err)
	}
	for _, marker := range []string{completeName, usedName} {
		if err := os.WriteFile(filepath.Join(path, marker), nil, 0600); err != nil {
			t.Fatalf("could not write %s: %v", marker, err)
		}
	}
	stamp := time.Now().Add(-used)
	if err := os.Chtimes(filepath.Join(path, usedName), stamp, stamp); err != nil {
		t.Fatalf("could not date %s: %v", path, err)
	}
	return path
}

func TestSweepRemovesTheUnusedAndKeepsTheRest(t *testing.T) {
	appRoot := t.TempDir()
	stale := unpackedApp(t, appRoot, "stale", 31*24*time.Hour)
	fresh := unpackedApp(t, appRoot, "fresh", 29*24*time.Hour)
	running := unpackedApp(t, appRoot, "running", 400*24*time.Hour)
	staging := filepath.Join(appRoot, stagingPrefix+"abandoned")
	if err := os.MkdirAll(staging, 0700); err != nil {
		t.Fatalf("could not build the staging directory: %v", err)
	}

	sweepApps(appRoot, "running", time.Now())

	if _, err := os.Stat(stale); !os.IsNotExist(err) {
		t.Errorf("a directory unused for 31 days stayed")
	}
	if _, err := os.Stat(fresh); err != nil {
		t.Errorf("a directory unused for 29 days went: %v", err)
	}
	if _, err := os.Stat(running); err != nil {
		t.Errorf("the directory this start runs went: %v", err)
	}
	if _, err := os.Stat(staging); !os.IsNotExist(err) {
		t.Errorf("an abandoned staging directory stayed")
	}
}

// Environment names the child below reads. A child, not this process: the marker
// answers whether another process runs from an entry, and Windows refuses to delete
// a file this process still holds, which would fail the temporary directory cleanup.
const (
	holdEntryVariable   = "DRUPACK_TEST_HOLD_ENTRY"
	holdAckVariable     = "DRUPACK_TEST_HOLD_ACK"
	holdReleaseVariable = "DRUPACK_TEST_HOLD_RELEASE"
)

// TestHoldsUsageForAnotherProcess runs as that child. Started on its own it skips.
func TestHoldsUsageForAnotherProcess(t *testing.T) {
	entry := os.Getenv(holdEntryVariable)
	if entry == "" {
		t.Skip("child process of TestCleanupKeepsAnEntryARunningStartHolds")
	}
	if err := HoldUsage(entry); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(os.Getenv(holdAckVariable), nil, 0600); err != nil {
		t.Fatal(err)
	}
	release := os.Getenv(holdReleaseVariable)
	deadline := time.Now().Add(30 * time.Second)
	for time.Now().Before(deadline) {
		if _, err := os.Stat(release); err == nil {
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("the parent never released this holder")
}

// holdUsageInChild starts that child on entry and returns once it holds the marker.
// The handshake files live in work, outside the cache the caller sweeps.
func holdUsageInChild(t *testing.T, work, entry string) {
	t.Helper()
	ack := filepath.Join(work, "held")
	release := filepath.Join(work, "release")
	child := exec.Command(os.Args[0], "-test.run=TestHoldsUsageForAnotherProcess")
	child.Env = append(os.Environ(),
		holdEntryVariable+"="+entry, holdAckVariable+"="+ack, holdReleaseVariable+"="+release)
	if err := child.Start(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		os.WriteFile(release, nil, 0600)
		child.Wait()
	})
	deadline := time.Now().Add(30 * time.Second)
	for time.Now().Before(deadline) {
		if _, err := os.Stat(ack); err == nil {
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("the holder never took the usage marker")
}

// A server that started more than unusedFor ago refreshes no timestamp, so the
// sweep and the clean command both ask the entry itself whether a process holds it.
func TestCleanupKeepsAnEntryARunningStartHolds(t *testing.T) {
	root := t.TempDir()
	appRoot := AppRoot(root)
	if err := os.MkdirAll(appRoot, rootMode); err != nil {
		t.Fatal(err)
	}
	running := unpackedApp(t, appRoot, "running", 400*24*time.Hour)
	stale := unpackedApp(t, appRoot, "stale", 400*24*time.Hour)
	holdUsageInChild(t, root, running)

	sweepApps(appRoot, "other", time.Now())

	if _, err := os.Stat(running); err != nil {
		t.Errorf("the sweep removed a directory a start holds: %v", err)
	}
	if _, err := os.Stat(stale); !os.IsNotExist(err) {
		t.Errorf("the sweep kept a directory no start holds")
	}

	var out bytes.Buffer
	if err := CleanApps(root, "acme", false, &out); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(running); err != nil {
		t.Errorf("clean removed a directory a start holds: %v", err)
	}
	if !strings.Contains(out.String(), "in use by a running site") {
		t.Errorf("clean did not report the held directory: %q", out.String())
	}
}

// TestSweepDatesAnUnmarkedDirectory covers a release unpacked before this one
// wrote markers, which has to survive its first sweep.
func TestSweepDatesAnUnmarkedDirectory(t *testing.T) {
	appRoot := t.TempDir()
	unmarked := filepath.Join(appRoot, "unmarked")
	if err := os.MkdirAll(unmarked, 0700); err != nil {
		t.Fatalf("could not build %s: %v", unmarked, err)
	}

	sweepApps(appRoot, "running", time.Now())

	if _, err := os.Stat(filepath.Join(unmarked, usedName)); err != nil {
		t.Fatalf("the sweep left no marker: %v", err)
	}
	sweepApps(appRoot, "running", time.Now())
	if _, err := os.Stat(unmarked); err != nil {
		t.Errorf("a directory the sweep had just dated went: %v", err)
	}
}

// TestPrepareAppUnpacksAnEmptyArchive covers a launcher built with no site,
// which the conformance fixtures pack. The archive names no directory, so the
// unpacking has to create the one it writes into.
func TestPrepareAppUnpacksAnEmptyArchive(t *testing.T) {
	root := t.TempDir()
	payload := &bytes.Buffer{}
	compressor, err := zstd.NewWriter(payload)
	if err != nil {
		t.Fatalf("could not compress: %v", err)
	}
	if err := compressor.Close(); err != nil {
		t.Fatalf("could not close the compressor: %v", err)
	}

	notice := &bytes.Buffer{}
	entry, err := PrepareApp(root, "abc123def4567890", payload.Bytes(), notice, func(string) error { return nil })
	if err != nil {
		t.Fatalf("PrepareApp failed: %v", err)
	}
	if !complete(entry) {
		t.Errorf("%s carries no completion marker", entry)
	}
	if strings.Contains(notice.String(), "0 of 0 MB") {
		t.Errorf("an empty archive reported progress: %q", notice.String())
	}

	notice.Reset()
	if _, err := PrepareApp(root, "abc123def4567890", payload.Bytes(), notice, func(string) error { return nil }); err != nil {
		t.Fatalf("the second PrepareApp failed: %v", err)
	}
	if notice.Len() != 0 {
		t.Errorf("a second start reported %q", notice.String())
	}
}

func TestCleanNodeListsThenRemovesEachReleaseAndKeepsOneInUse(t *testing.T) {
	root := t.TempDir()
	for _, name := range []string{"v24.21.0-aaaaaaaaaaaa", "v22.30.0-bbbbbbbbbbbb", "v26.1.0-cccccccccccc"} {
		if err := os.MkdirAll(filepath.Join(NodeRoot(root), name, "bin"), 0700); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(NodeRoot(root), name, "bin", "node"), make([]byte, 2*megabyte), 0700); err != nil {
			t.Fatal(err)
		}
	}
	running := filepath.Join(NodeRoot(root), "v26.1.0-cccccccccccc")
	holdUsageInChild(t, root, running)

	var listing bytes.Buffer
	if err := CleanNode(root, true, &listing); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(listing.String(), "  node/v24.21.0-aaaaaaaaaaaa  2 MB\n") ||
		!strings.Contains(listing.String(), "4 MB in 2 unpacked Node releases.") {
		t.Fatalf("clean --dry-run printed %q", listing.String())
	}
	if _, err := os.Stat(filepath.Join(NodeRoot(root), "v24.21.0-aaaaaaaaaaaa")); err != nil {
		t.Fatalf("a dry run removed a release: %v", err)
	}

	var removal bytes.Buffer
	if err := CleanNode(root, false, &removal); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(removal.String(), "Removed 2 unpacked Node releases, freeing 4 MB.") ||
		!strings.Contains(removal.String(), "Kept 1 unpacked Node release a running site still uses.") {
		t.Fatalf("clean printed %q", removal.String())
	}
	entries, _ := os.ReadDir(NodeRoot(root))
	for _, entry := range entries {
		if entry.IsDir() && entry.Name() != "v26.1.0-cccccccccccc" {
			t.Errorf("clean left %s", entry.Name())
		}
	}
}

func TestCleanNodeSaysNothingWhereNoReleaseWasUnpacked(t *testing.T) {
	var out bytes.Buffer
	if err := CleanNode(t.TempDir(), false, &out); err != nil || out.Len() != 0 {
		t.Fatalf("CleanNode = %v, printed %q; want nothing", err, out.String())
	}
}

func TestPrepareNodeUnpacksOnceUnderTheNodeDirectory(t *testing.T) {
	tree := t.TempDir()
	if err := os.MkdirAll(filepath.Join(tree, "bin"), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(tree, "bin", "node"), []byte("node"), 0700); err != nil {
		t.Fatal(err)
	}
	payload, m, err := Build(tree, "24.21.0", "bin/node")
	if err != nil {
		t.Fatal(err)
	}
	root := t.TempDir()
	var notice bytes.Buffer
	entry, err := PrepareNode(root, payload, m, &notice, func(string) error { return nil })
	if err != nil {
		t.Fatal(err)
	}
	if filepath.Dir(entry) != NodeRoot(root) || !strings.Contains(notice.String(), "Unpacking Node 24.21.0.") {
		t.Fatalf("PrepareNode = %s, printed %q", entry, notice.String())
	}
	before, err := os.Stat(entry)
	if err != nil {
		t.Fatal(err)
	}
	notice.Reset()
	if again, err := PrepareNode(root, payload, m, &notice, func(string) error { return nil }); err != nil || again != entry || notice.Len() != 0 {
		t.Fatalf("a second PrepareNode = %s, %v, printed %q", again, err, notice.String())
	}
	if after, _ := os.Stat(entry); !after.ModTime().Equal(before.ModTime()) {
		t.Fatal("a second PrepareNode changed the release directory")
	}
}

// removeOthers renames an entry aside before deleting it and tests the marker
// again there, so a start that held the marker after the first test still keeps
// its files.
func TestRemoveOthersKeepsAnEntryAnotherProcessHolds(t *testing.T) {
	root := t.TempDir()
	work := t.TempDir()
	held := filepath.Join(root, "vheld-000000000000")
	free := filepath.Join(root, "vfree-000000000000")
	for _, entry := range []string{held, free} {
		if err := os.MkdirAll(entry, 0700); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(entry, "payload"), []byte("x"), 0600); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(entry, ManifestName), []byte(`{"version":"1.0.0","entry":"payload","files":[{"path":"payload","size":1,"sha256":"00"}]}`), 0600); err != nil {
			t.Fatal(err)
		}
	}
	holdUsageInChild(t, work, held)

	removeOthers(root, "vactive-000000000000")

	if _, err := os.Stat(filepath.Join(held, "payload")); err != nil {
		t.Errorf("removeOthers took a file from an entry another process holds: %v", err)
	}
	if _, err := os.Stat(free); !os.IsNotExist(err) {
		t.Errorf("removeOthers kept an entry nothing holds: %v", err)
	}
	leftovers, _ := filepath.Glob(filepath.Join(root, "*"+stagingPrefix+"*"))
	if len(leftovers) != 0 {
		t.Errorf("removeOthers left staging directories behind: %v", leftovers)
	}
}

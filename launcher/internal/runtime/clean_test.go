package runtime

import (
	"bytes"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// cleanKinds names each kind clean walks: where its entries live under the
// cache root, the prefix its listing gives them, and the words its totals use.
var cleanKinds = []struct {
	kind, label, one, many string
	dir                    func(root string) string
}{
	{"runtime", "", "unpacked runtime", "unpacked runtimes", func(root string) string { return root }},
	{"application", "", "unpacked application", "unpacked applications", AppRoot},
	{"Node", "node/", "unpacked Node release", "unpacked Node releases", NodeRoot},
}

// cleanEntry writes a 2 MB entry at path, carrying the manifest a runtime entry
// needs to count as one.
func cleanEntry(t *testing.T, path string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Join(path, "bin"), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(path, "bin", "entry"), make([]byte, 2*megabyte), 0700); err != nil {
		t.Fatal(err)
	}
	manifest := fmt.Sprintf(`{"version":"1.0.0","entry":"bin/entry","files":[{"path":"bin/entry","size":%d,"sha256":"00"}]}`, 2*megabyte)
	if err := os.WriteFile(filepath.Join(path, ManifestName), []byte(manifest), 0600); err != nil {
		t.Fatal(err)
	}
}

func TestCleanListsThenRemovesEachKindAndKeepsAHeldEntry(t *testing.T) {
	for _, c := range cleanKinds {
		t.Run(c.kind, func(t *testing.T) {
			root := t.TempDir()
			dir := c.dir(root)
			names := []string{"v1.0.0-aaaaaaaaaaaa", "v2.0.0-bbbbbbbbbbbb", "v3.0.0-cccccccccccc"}
			for _, name := range names {
				cleanEntry(t, filepath.Join(dir, name))
			}
			// DRUPACK_CACHE_DIR can name a directory the reader already uses.
			reader := filepath.Join(root, "someone-elses-project")
			if err := os.MkdirAll(filepath.Join(reader, "src"), 0700); err != nil {
				t.Fatal(err)
			}
			running := filepath.Join(dir, names[2])
			holdUsageInChild(t, t.TempDir(), running)
			cache := NewCache(root, noHold)

			var listing bytes.Buffer
			if err := cache.Clean("acme", true, &listing); err != nil {
				t.Fatal(err)
			}
			for _, want := range []string{
				"  " + c.label + names[0] + "  2 MB\n",
				"  " + c.label + names[2] + "  2 MB  in use by a running site, kept\n",
				"4 MB in 2 " + c.many + ".\n",
				"Run acme clean to remove them.\n",
			} {
				if !strings.Contains(listing.String(), want) {
					t.Errorf("clean --dry-run printed %q, want a line %q", listing.String(), want)
				}
			}
			if strings.Contains(listing.String(), "someone-elses-project") {
				t.Errorf("clean --dry-run listed the reader's directory: %q", listing.String())
			}
			if _, err := os.Stat(filepath.Join(dir, names[0])); err != nil {
				t.Fatalf("a dry run removed an entry: %v", err)
			}

			var removal bytes.Buffer
			if err := cache.Clean("acme", false, &removal); err != nil {
				t.Fatal(err)
			}
			for _, want := range []string{
				"Removed 2 " + c.many + ", freeing 4 MB.\n",
				"Kept 1 " + c.one + " a running site still uses.",
			} {
				if !strings.Contains(removal.String(), want) {
					t.Errorf("clean printed %q, want a line %q", removal.String(), want)
				}
			}
			for _, name := range names[:2] {
				if _, err := os.Stat(filepath.Join(dir, name)); !os.IsNotExist(err) {
					t.Errorf("clean left %s", name)
				}
			}
			if _, err := os.Stat(filepath.Join(running, "bin", "entry")); err != nil {
				t.Errorf("clean took a file from an entry a start holds: %v", err)
			}
			if _, err := os.Stat(filepath.Join(reader, "src")); err != nil {
				t.Errorf("clean removed the reader's directory: %v", err)
			}
			leftovers, _ := filepath.Glob(filepath.Join(dir, stagingPrefix+"*"))
			if len(leftovers) != 0 {
				t.Errorf("clean left staging directories behind: %v", leftovers)
			}
		})
	}
}

func TestCleanSaysSoWhereNothingWasUnpacked(t *testing.T) {
	root := t.TempDir()
	var out bytes.Buffer
	if err := NewCache(root, noHold).Clean("acme", false, &out); err != nil {
		t.Fatal(err)
	}
	if want := "Nothing to clean in " + root + ".\n"; out.String() != want {
		t.Fatalf("clean printed %q, want %q", out.String(), want)
	}
}

package runtime_test

import (
	"bytes"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/klauspost/compress/zstd"

	runtimepkg "git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

const releaseAppChecksum = "abc123def4567890abc123def4567890"

func emptyAppPayload(t *testing.T) []byte {
	t.Helper()
	payload := &bytes.Buffer{}
	compressor, err := zstd.NewWriter(payload)
	if err != nil {
		t.Fatal(err)
	}
	if err := compressor.Close(); err != nil {
		t.Fatal(err)
	}
	return payload.Bytes()
}

func activeName(t *testing.T, root string) string {
	t.Helper()
	active, err := os.ReadFile(filepath.Join(root, "active"))
	if err != nil {
		t.Fatal(err)
	}
	return strings.TrimSpace(string(active))
}

// Each row starts from a cache where release 1.0.0 is active, then prepares 2.0.0.
func TestPrepareReleaseActivatesOnlyAWholeRelease(t *testing.T) {
	payload, installed := buildFixture(t)
	app := emptyAppPayload(t)
	upgrade := installed
	upgrade.Version = "2.0.0"

	cases := map[string]struct {
		payload    []byte
		appPayload []byte
		step       string
	}{
		"both components prepare":                      {payload, app, ""},
		"the application fails after a staged runtime": {payload, []byte("not a zstd frame"), "application"},
		"the runtime fails":                            {[]byte("not a zstd frame"), app, "runtime"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			root := t.TempDir()
			previous, err := prepareAndActivate(root, payload, installed, io.Discard)
			if err != nil {
				t.Fatal(err)
			}

			release := runtimepkg.Release{Payload: c.payload, Manifest: upgrade,
				AppChecksum: releaseAppChecksum, AppPayload: c.appPayload}
			runtimeDir, _, err := runtimepkg.PrepareRelease(root, release, io.Discard)

			if c.step == "" {
				if err != nil {
					t.Fatal(err)
				}
				if activeName(t, root) != filepath.Base(runtimeDir) {
					t.Fatalf("active names %q, want %q", activeName(t, root), filepath.Base(runtimeDir))
				}
				if _, err := os.Stat(previous); !os.IsNotExist(err) {
					t.Fatalf("the previous release's runtime survived the upgrade: %v", err)
				}
				return
			}

			if err == nil {
				t.Fatal("a failed step returned no error")
			}
			for _, want := range []string{runtimepkg.Key("2.0.0", c.payload),
				releaseAppChecksum, "at the " + c.step + " step"} {
				if !strings.Contains(err.Error(), want) {
					t.Errorf("error %q does not name %q", err.Error(), want)
				}
			}
			if activeName(t, root) != filepath.Base(previous) {
				t.Fatalf("active names %q after a failed step, want the previous %q",
					activeName(t, root), filepath.Base(previous))
			}
			if _, err := os.Stat(filepath.Join(previous, "bin", "app")); err != nil {
				t.Fatalf("the previous release's runtime did not survive: %v", err)
			}
			if c.step == "runtime" {
				if _, err := os.Stat(runtimepkg.AppRoot(root)); !os.IsNotExist(err) {
					t.Fatalf("a failed runtime step unpacked an application: %v", err)
				}
			}
		})
	}
}

package node_test

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/ProtonMail/go-crypto/openpgp"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/node"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// index lists releases out of order, and v25.1.0 lacks a linux-arm64 build.
const index = `[
  {"version": "v24.20.0", "lts": "Krypton", "files": ["linux-x64", "linux-arm64", "osx-x64-tar", "osx-arm64-tar", "win-x64-zip"]},
  {"version": "v26.10.0", "lts": false, "files": ["linux-x64", "linux-arm64", "osx-x64-tar", "osx-arm64-tar", "win-x64-zip"]},
  {"version": "v25.1.0", "lts": false, "files": ["linux-x64", "osx-x64-tar", "osx-arm64-tar", "win-x64-zip"]},
  {"version": "v24.21.0", "lts": "Krypton", "files": ["linux-x64", "linux-arm64", "linux-x64-musl", "osx-x64-tar", "osx-arm64-tar", "win-x64-zip"]},
  {"version": "v22.30.0", "lts": "Jod", "files": ["linux-x64", "linux-arm64", "osx-x64-tar", "osx-arm64-tar", "win-x64-zip"]}
]`

var archiveSuffixes = []string{"linux-x64.tar.gz", "linux-arm64.tar.gz", "darwin-x64.tar.gz", "darwin-arm64.tar.gz", "win-x64.zip"}

// dist serves a release directory: the index, and for each release its
// archives and a SHASUMS256.txt that signer signs.
type dist struct {
	files  map[string][]byte
	signer *openpgp.Entity
	server *httptest.Server
}

func newDist(t *testing.T) *dist {
	t.Helper()
	d := &dist{files: map[string][]byte{"/index.json": []byte(index)}, signer: newKey(t)}
	for _, tag := range []string{"v24.20.0", "v26.10.0", "v25.1.0", "v24.21.0", "v22.30.0"} {
		var sums strings.Builder
		for _, suffix := range archiveSuffixes {
			name := "node-" + tag + "-" + suffix
			d.files["/"+tag+"/"+name] = []byte("archive " + name)
			sums.WriteString(sha256Hex(d.files["/"+tag+"/"+name]) + "  " + name + "\n")
		}
		d.files["/"+tag+"/SHASUMS256.txt"] = []byte(sums.String())
		d.sign(t, tag, d.signer)
	}
	d.server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		content, ok := d.files[r.URL.Path]
		if !ok {
			http.NotFound(w, r)
			return
		}
		w.Write(content)
	}))
	t.Cleanup(d.server.Close)
	return d
}

func newKey(t *testing.T) *openpgp.Entity {
	t.Helper()
	entity, err := openpgp.NewEntity("Test Releaser", "", "releaser@example.org", nil)
	if err != nil {
		t.Fatal(err)
	}
	return entity
}

// sign writes tag's SHASUMS256.txt.sig as signer's detached signature.
func (d *dist) sign(t *testing.T, tag string, signer *openpgp.Entity) {
	t.Helper()
	var signature bytes.Buffer
	if err := openpgp.DetachSign(&signature, signer, bytes.NewReader(d.files["/"+tag+"/SHASUMS256.txt"]), nil); err != nil {
		t.Fatal(err)
	}
	d.files["/"+tag+"/SHASUMS256.txt.sig"] = signature.Bytes()
}

func (d *dist) request(t *testing.T, value siteconfig.Node, targets ...string) node.Request {
	return node.Request{Value: value, Targets: targets, Dir: t.TempDir(), Dist: d.server.URL,
		Keyring: openpgp.EntityList{d.signer}}
}

func sha256Hex(content []byte) string {
	sum := sha256.Sum256(content)
	return hex.EncodeToString(sum[:])
}

func TestEachValueFormPicksItsRelease(t *testing.T) {
	d := newDist(t)
	for value, want := range map[siteconfig.Node]string{
		siteconfig.NodeLTS: "24.21.0",
		"24":               "24.21.0",
		"26":               "26.10.0",
		"22":               "22.30.0",
		"24.20.0":          "24.20.0",
	} {
		r := d.request(t, value, "linux-amd64")
		resolved, err := node.Resolve(r)
		if err != nil {
			t.Errorf("%s: %v", value, err)
			continue
		}
		if resolved.Version != want {
			t.Errorf("%s resolves to %s; want %s", value, resolved.Version, want)
		}
		path := filepath.Join(r.Dir, "linux-amd64.tar.gz")
		if resolved.Archives["linux-amd64"] != path {
			t.Errorf("%s: archives = %v; want %s", value, resolved.Archives, path)
		}
		if content, err := os.ReadFile(path); err != nil || string(content) != "archive node-v"+want+"-linux-x64.tar.gz" {
			t.Errorf("%s: the archive holds %q, %v", value, content, err)
		}
	}
}

func TestEachTargetGetsItsBuildAndMuslGetsNone(t *testing.T) {
	d := newDist(t)
	r := d.request(t, "24", "linux-amd64", "linux-amd64-musl", "linux-arm64", "linux-arm64-musl",
		"macos-amd64", "macos-arm64", "windows-amd64")
	resolved, err := node.Resolve(r)
	if err != nil {
		t.Fatal(err)
	}
	for target, suffix := range map[string]string{
		"linux-amd64": "linux-x64.tar.gz", "linux-arm64": "linux-arm64.tar.gz",
		"macos-amd64": "darwin-x64.tar.gz", "macos-arm64": "darwin-arm64.tar.gz", "windows-amd64": "win-x64.zip",
	} {
		path, carried := node.Archive(r.Dir, target)
		if !carried || resolved.Archives[target] != path {
			t.Errorf("%s: archive %q; want %q", target, resolved.Archives[target], path)
		}
		if content, _ := os.ReadFile(path); string(content) != "archive node-v24.21.0-"+suffix {
			t.Errorf("%s: %s holds %q; want node-v24.21.0-%s", target, path, content, suffix)
		}
	}
	if len(resolved.Archives) != 5 {
		t.Errorf("archives = %v; want none for a musl target", resolved.Archives)
	}
}

func TestAMuslOnlyBuildResolvesTheVersionAndFetchesNothing(t *testing.T) {
	d := newDist(t)
	delete(d.files, "/v24.21.0/SHASUMS256.txt")
	resolved, err := node.Resolve(d.request(t, siteconfig.NodeLTS, "linux-amd64-musl", "linux-arm64-musl"))
	if err != nil {
		t.Fatal(err)
	}
	if resolved.Version != "24.21.0" || len(resolved.Archives) != 0 {
		t.Fatalf("resolution = %+v; want 24.21.0 and no archive", resolved)
	}
}

func TestAVersionMissingFromTheIndexStops(t *testing.T) {
	d := newDist(t)
	for value, want := range map[siteconfig.Node]string{"24.99.0": "node 24.99.0", "23": "node 23"} {
		if _, err := node.Resolve(d.request(t, value, "linux-amd64")); err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%s: Resolve error = %v; want one naming %q", value, err, want)
		}
	}
}

func TestATargetWithoutABuildStopsAndNamesIt(t *testing.T) {
	d := newDist(t)
	_, err := node.Resolve(d.request(t, "25", "linux-amd64", "linux-arm64"))
	if err == nil || !strings.Contains(err.Error(), "linux-arm64") {
		t.Fatalf("Resolve error = %v; want one naming linux-arm64/glibc", err)
	}
}

func TestABadSignatureStops(t *testing.T) {
	d := newDist(t)
	sums := "/v24.21.0/SHASUMS256.txt"
	d.files[sums] = bytes.Replace(d.files[sums], []byte("node-v24.21.0-win-x64.zip"), []byte("node-v24.21.0-win-x64.exe"), 1)
	r := d.request(t, "24", "linux-amd64")
	if _, err := node.Resolve(r); err == nil || !strings.Contains(err.Error(), "signature does not verify") {
		t.Fatalf("Resolve error = %v; want one refusing the signature", err)
	}
	if entries, _ := os.ReadDir(r.Dir); len(entries) != 0 {
		t.Fatalf("a refused signature left %s in the archive directory", entries[0].Name())
	}
}

func TestAnUnknownKeyStopsAndNamesTheKeyring(t *testing.T) {
	d := newDist(t)
	d.sign(t, "v24.21.0", newKey(t))
	if _, err := node.Resolve(d.request(t, "24", "linux-amd64")); err == nil || !strings.Contains(err.Error(), node.KeyringFile) {
		t.Fatalf("Resolve error = %v; want one naming %s", err, node.KeyringFile)
	}
}

func TestTheKeptKeyringHoldsNoTestKey(t *testing.T) {
	d := newDist(t)
	r := d.request(t, "24", "linux-amd64")
	r.Keyring = nil
	if _, err := node.Resolve(r); err == nil || !strings.Contains(err.Error(), node.KeyringFile) {
		t.Fatalf("Resolve error = %v; want the kept keyring to refuse the test key", err)
	}
}

func TestAHashMismatchStopsAndKeepsNoFile(t *testing.T) {
	d := newDist(t)
	d.files["/v24.21.0/node-v24.21.0-linux-arm64.tar.gz"] = []byte("tampered")
	r := d.request(t, "24", "linux-amd64", "linux-arm64")
	_, err := node.Resolve(r)
	if err == nil || !strings.Contains(err.Error(), "node-v24.21.0-linux-arm64.tar.gz has SHA-256") {
		t.Fatalf("Resolve error = %v; want one naming the mismatched archive", err)
	}
	if _, err := os.Stat(filepath.Join(r.Dir, "linux-arm64.tar.gz")); !os.IsNotExist(err) {
		t.Fatalf("the mismatched archive was kept: %v", err)
	}
}

func TestAnArchiveAlreadyVerifiedIsKeptAndACorruptOneReplaced(t *testing.T) {
	d := newDist(t)
	r := d.request(t, "24", "linux-amd64", "linux-arm64")
	if _, err := node.Resolve(r); err != nil {
		t.Fatal(err)
	}
	delete(d.files, "/v24.21.0/node-v24.21.0-linux-x64.tar.gz")
	corrupt := filepath.Join(r.Dir, "linux-arm64.tar.gz")
	if err := os.WriteFile(corrupt, []byte("truncated"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := node.Resolve(r); err != nil {
		t.Fatalf("a second resolution fetched the kept archive again: %v", err)
	}
	if content, _ := os.ReadFile(corrupt); string(content) != "archive node-v24.21.0-linux-arm64.tar.gz" {
		t.Fatalf("the corrupt archive holds %q; want a fresh download", content)
	}
}

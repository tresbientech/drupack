package main

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/build"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

func buildRuntime(t *testing.T) ([]byte, runtime.Manifest) {
	t.Helper()
	directory := t.TempDir()
	if err := os.WriteFile(filepath.Join(directory, "drupack"), []byte("entry"), 0700); err != nil {
		t.Fatal(err)
	}
	payload, manifest, err := runtime.Build(directory, "1.0.0", "drupack")
	if err != nil {
		t.Fatalf("Build: %v", err)
	}
	return payload, manifest
}

func source(t *testing.T) string {
	t.Helper()
	directory := t.TempDir()
	if err := os.WriteFile(filepath.Join(directory, "payload.go"), []byte("package main\n"), 0600); err != nil {
		t.Fatal(err)
	}
	return directory
}

// payloadDir lays out a payload directory; nodeFiles are archive names under node/.
func payloadDir(t *testing.T, siteJSON string, nodeFiles ...string) string {
	t.Helper()
	directory := t.TempDir()
	files := map[string]string{build.PayloadName: "compressed", build.ChecksumName: "abc123\n"}
	if siteJSON != "" {
		files[siteconfig.OutputName] = siteJSON
	}
	for _, name := range nodeFiles {
		files[filepath.Join(build.NodeDirName, name)] = "archive"
	}
	for name, content := range files {
		path := filepath.Join(directory, name)
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	return directory
}

const acmeSite = `{"name":"acme","drupal":"11.4.7","drush":"13.8.0"}`

const acmeNodeSite = `{"name":"acme","node":"24.21.0","drupal":"11.4.7","drush":"13.8.0"}`

func TestParseTarget(t *testing.T) {
	for target, want := range map[string][3]string{
		"linux-amd64":      {"linux-amd64", "amd64", "glibc"},
		"linux-arm64-musl": {"linux-arm64", "arm64", "musl"},
		"macos-arm64":      {"macos-arm64", "arm64", "glibc"},
		"windows-amd64":    {"windows-amd64", "amd64", "glibc"},
	} {
		platform, arch, libc, err := parseTarget(target)
		if err != nil || [3]string{platform, arch, libc} != want {
			t.Errorf("parseTarget(%q) = %s %s %s, %v; want %v", target, platform, arch, libc, err, want)
		}
	}
	for _, target := range []string{"", "linux", "linux-riscv64", "linux-amd64-uclibc", "amd64"} {
		if _, _, _, err := parseTarget(target); err == nil {
			t.Errorf("parseTarget(%q) accepted a name outside targets.json", target)
		}
	}
}

func TestEntryNameIsFrankenphpExeOnWindowsOnly(t *testing.T) {
	for platform, want := range map[string]string{"windows-amd64": "frankenphp.exe", "linux-amd64": "drupack", "macos-arm64": "drupack"} {
		if got := entryName(platform); got != want {
			t.Errorf("entryName(%q) = %q; want %q", platform, got, want)
		}
	}
}

func TestWriteBuildCopyCarriesTheRuntime(t *testing.T) {
	build := t.TempDir()
	payload, manifest := buildRuntime(t)
	packing, err := resolve(options{target: "linux-amd64", payload: payloadDir(t, acmeSite), version: "1.0.0", siteVersion: "1.4.0"})
	if err != nil {
		t.Fatal(err)
	}
	if err := writeBuildCopy(build, source(t), payload, manifest, packing); err != nil {
		t.Fatalf("writeBuildCopy: %v", err)
	}
	if carried := readFile(t, filepath.Join(build, "runtime.tar.zst")); carried != string(payload) {
		t.Fatal("runtime.tar.zst differs from the packed payload")
	}
	var m runtime.Manifest
	if err := json.Unmarshal([]byte(readFile(t, filepath.Join(build, "manifest.json"))), &m); err != nil {
		t.Fatalf("manifest.json: %v", err)
	}
	if m.Entry != "drupack" {
		t.Fatalf("manifest.json names entry %q", m.Entry)
	}
	if got := readFile(t, filepath.Join(build, "app.tar.zst")); got != "compressed" {
		t.Errorf("app.tar.zst = %q", got)
	}
	if got := readFile(t, filepath.Join(build, "app_checksum.txt")); got != "abc123" {
		t.Errorf("app_checksum.txt = %q; want the checksum without its newline", got)
	}
	generated := readFile(t, filepath.Join(build, "payload.go"))
	for _, want := range []string{
		"//go:embed runtime.tar.zst\nvar runtimePayload []byte",
		"//go:embed manifest.json\nvar runtimeManifest []byte",
		"var siteName = \"acme\"",
		"var siteVersion = \"1.4.0\"",
		"var siteComponents = \"Drupal 11.4.7\\nDrush 13.8.0\"",
		"var engine = false",
	} {
		if !strings.Contains(generated, want) {
			t.Fatalf("payload.go does not carry %q:\n%s", want, generated)
		}
	}
}

func TestASiteNamesNodeOnlyWhenItCarriesIt(t *testing.T) {
	archive := func(directory, name string) string { return filepath.Join(directory, build.NodeDirName, name) }
	glibc := payloadDir(t, acmeNodeSite, "linux-amd64.tar.gz")
	musl := payloadDir(t, acmeNodeSite)
	missing := payloadDir(t, acmeNodeSite)
	unnamed := payloadDir(t, acmeSite, "linux-amd64.tar.gz")
	for _, row := range []struct {
		name, target, directory, archive string
		components                       string
		fails                            bool
	}{
		{"glibc carries", "linux-amd64", glibc, archive(glibc, "linux-amd64.tar.gz"), "Node 24.21.0\nDrupal 11.4.7\nDrush 13.8.0", false},
		{"musl carries none", "linux-amd64-musl", musl, "", "Drupal 11.4.7\nDrush 13.8.0", false},
		{"a site naming no Node carries none", "linux-amd64", unnamed, "", "Drupal 11.4.7\nDrush 13.8.0", false},
		{"a missing archive fails", "linux-amd64", missing, "", "", true},
	} {
		got, err := resolve(options{target: row.target, payload: row.directory, version: "1.0.0"})
		if (err != nil) != row.fails {
			t.Errorf("%s: err = %v", row.name, err)
			continue
		}
		if row.fails {
			continue
		}
		if got.nodeArchive != row.archive || strings.Join(got.site.components, "\n") != row.components {
			t.Errorf("%s: archive %q components %q; want %q %q", row.name, got.nodeArchive, got.site.components, row.archive, row.components)
		}
	}
}

func TestPackPrintsItsResolvedInputs(t *testing.T) {
	directory := payloadDir(t, acmeNodeSite, "linux-arm64.tar.gz")
	for _, row := range []struct {
		target, payload string
		engine          bool
		want            []string
	}{
		{"linux-arm64", directory, false, []string{
			"target linux-arm64\n", "architecture arm64\n", "entry drupack\n", "libc glibc\n", "payload " + directory + "\n",
			"application checksum abc123\n", "Node archive " + filepath.Join(directory, "node", "linux-arm64.tar.gz") + "\n",
			"site acme\n", "site version 1.4.0\n", "engine version 1.0.0\n"}},
		{"windows-amd64", payloadDir(t, ""), true, []string{"entry frankenphp.exe\n", "Node archive none\n", "site drupack\n", "site version 1.0.0\n"}},
	} {
		got, err := resolve(options{target: row.target, payload: row.payload, version: "1.0.0", siteVersion: "1.4.0", engine: row.engine})
		if err != nil {
			t.Fatal(err)
		}
		var printed bytes.Buffer
		printInputs(&printed, got)
		for _, want := range row.want {
			if !strings.Contains(printed.String(), want) {
				t.Errorf("%s prints\n%s\nwant a line %q", row.target, printed.String(), want)
			}
		}
	}
}

func readFile(t *testing.T, path string) string {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return string(data)
}

// packedSource resolves a pack of target and returns the payload.go its build copy gets.
func packedSource(t *testing.T, directory string, o options) string {
	t.Helper()
	o.payload, o.version = directory, "0.5.0"
	packing, err := resolve(o)
	if err != nil {
		t.Fatal(err)
	}
	build := t.TempDir()
	payload, manifest := buildRuntime(t)
	if err := writeBuildCopy(build, source(t), payload, manifest, packing); err != nil {
		t.Fatal(err)
	}
	return readFile(t, filepath.Join(build, "payload.go"))
}

func TestTheEngineExecutableCarriesTheEngineMark(t *testing.T) {
	generated := packedSource(t, payloadDir(t, ""), options{target: "linux-amd64", engine: true})
	for _, want := range []string{"var siteName = \"drupack\"", "var siteVersion = \"0.5.0\"", "var engine = true"} {
		if !strings.Contains(generated, want) {
			t.Fatalf("payload.go does not carry %q:\n%s", want, generated)
		}
	}
}

func TestAMuslFileCarriesTheMuslMark(t *testing.T) {
	for target, musl := range map[string]bool{"linux-amd64": false, "linux-amd64-musl": true} {
		generated := packedSource(t, payloadDir(t, ""), options{target: target, engine: true})
		if want := fmt.Sprintf("var musl = %t", musl); !strings.Contains(generated, want) {
			t.Fatalf("%s: payload.go does not carry %q:\n%s", target, want, generated)
		}
	}
}

func TestWriteNodePayloadCarriesTheReleaseOrNothing(t *testing.T) {
	archive := filepath.Join(t.TempDir(), "linux-amd64.tar.gz")
	writeNodeArchive(t, archive)
	build := t.TempDir()
	if err := writeNodePayload(build, archive, "24.21.0"); err != nil {
		t.Fatal(err)
	}
	m, err := runtime.ParseManifest([]byte(readFile(t, filepath.Join(build, "node-manifest.json"))))
	if err != nil {
		t.Fatal(err)
	}
	if m.Version != "24.21.0" || m.Entry != "bin/node" || len(m.Files) != 2 {
		t.Fatalf("node-manifest.json = %+v; want 24.21.0 with bin/node and LICENSE", m)
	}
	if readFile(t, filepath.Join(build, "node.tar.zst")) == "" {
		t.Fatal("node.tar.zst is empty")
	}

	none := t.TempDir()
	if err := writeNodePayload(none, "", ""); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"node.tar.zst", "node-manifest.json"} {
		if content := readFile(t, filepath.Join(none, name)); content != "" {
			t.Errorf("%s holds %d bytes for a file carrying no Node", name, len(content))
		}
	}
}

func writeNodeArchive(t *testing.T, path string) {
	t.Helper()
	file, err := os.Create(path)
	if err != nil {
		t.Fatal(err)
	}
	defer file.Close()
	compressed := gzip.NewWriter(file)
	writer := tar.NewWriter(compressed)
	for name, content := range map[string]string{"node-v24.21.0-linux-x64/bin/node": "node", "node-v24.21.0-linux-x64/LICENSE": "MIT"} {
		if err := writer.WriteHeader(&tar.Header{Name: name, Mode: 0o755, Size: int64(len(content)), Typeflag: tar.TypeReg}); err != nil {
			t.Fatal(err)
		}
		writer.Write([]byte(content))
	}
	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}
	if err := compressed.Close(); err != nil {
		t.Fatal(err)
	}
}

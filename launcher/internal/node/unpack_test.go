package node_test

import (
	"archive/tar"
	"archive/zip"
	"bytes"
	"compress/gzip"
	"os"
	"os/exec"
	"path/filepath"
	goruntime "runtime"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/node"
)

// entry is one member of a fixture archive: a directory when content and link
// are empty and name ends in a slash, a link when link is set.
type entry struct{ name, content, link string }

func tarGz(t *testing.T, entries ...entry) string {
	t.Helper()
	var buffer bytes.Buffer
	compressed := gzip.NewWriter(&buffer)
	writer := tar.NewWriter(compressed)
	for _, e := range entries {
		header := &tar.Header{Name: e.name, Mode: 0o755, Typeflag: tar.TypeReg, Size: int64(len(e.content))}
		switch {
		case e.link != "":
			header.Typeflag, header.Linkname, header.Size = tar.TypeSymlink, e.link, 0
		case strings.HasSuffix(e.name, "/"):
			header.Typeflag = tar.TypeDir
		}
		if err := writer.WriteHeader(header); err != nil {
			t.Fatal(err)
		}
		if _, err := writer.Write([]byte(e.content)); err != nil {
			t.Fatal(err)
		}
	}
	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}
	if err := compressed.Close(); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "linux-amd64.tar.gz")
	if err := os.WriteFile(path, buffer.Bytes(), 0o644); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestUnpackStripsTheTopDirectoryAndTurnsBinLinksIntoScripts(t *testing.T) {
	archive := tarGz(t,
		entry{name: "node-v24.21.0-linux-x64/"},
		entry{name: "node-v24.21.0-linux-x64/LICENSE", content: "MIT"},
		entry{name: "node-v24.21.0-linux-x64/bin/node", content: "#!/bin/sh\nprintf '%s\\n' \"$@\"\n"},
		entry{name: "node-v24.21.0-linux-x64/lib/node_modules/npm/bin/npm-cli.js", content: "// npm"},
		entry{name: "node-v24.21.0-linux-x64/bin/npm", link: "../lib/node_modules/npm/bin/npm-cli.js"},
	)
	destination := filepath.Join(t.TempDir(), "with space")
	executable, err := node.Unpack(archive, destination)
	if err != nil {
		t.Fatal(err)
	}
	if executable != "bin/node" {
		t.Fatalf("executable = %q; want bin/node", executable)
	}
	if content, err := os.ReadFile(filepath.Join(destination, "LICENSE")); err != nil || string(content) != "MIT" {
		t.Fatalf("LICENSE = %q, %v", content, err)
	}
	if goruntime.GOOS == "windows" {
		return
	}
	out, err := exec.Command(filepath.Join(destination, "bin", "npm"), "install", "a b").Output()
	if err != nil {
		t.Fatal(err)
	}
	want := filepath.Join(destination, "bin") + "/../lib/node_modules/npm/bin/npm-cli.js\ninstall\na b\n"
	if string(out) != want {
		t.Fatalf("bin/npm ran node with %q; want %q", out, want)
	}
}

func TestUnpackRefusesALinkOutsideBinOrOutOfTheTree(t *testing.T) {
	for _, link := range []entry{
		{name: "node-v24.21.0-linux-x64/lib/npm", link: "node_modules/npm/bin/npm-cli.js"},
		{name: "node-v24.21.0-linux-x64/bin/npm", link: "../../../etc/passwd.js"},
		{name: "node-v24.21.0-linux-x64/bin/sh", link: "/bin/sh"},
	} {
		if _, err := node.Unpack(tarGz(t, link), t.TempDir()); err == nil || !strings.Contains(err.Error(), link.name[len("node-v24.21.0-linux-x64/"):]) {
			t.Errorf("%s -> %s: Unpack error = %v; want one naming the link", link.name, link.link, err)
		}
	}
}

func TestUnpackRefusesAPathOutOfTheTree(t *testing.T) {
	archive := tarGz(t, entry{name: "node-v24.21.0-linux-x64/../../escape", content: "x"})
	if _, err := node.Unpack(archive, t.TempDir()); err == nil || !strings.Contains(err.Error(), "unsafe path") {
		t.Fatalf("Unpack error = %v; want one refusing the path", err)
	}
}

func TestUnpackReadsTheWindowsZip(t *testing.T) {
	var buffer bytes.Buffer
	writer := zip.NewWriter(&buffer)
	for name, content := range map[string]string{
		"node-v24.21.0-win-x64/node.exe": "MZ", "node-v24.21.0-win-x64/npm.cmd": "@echo off",
		"node-v24.21.0-win-x64/node_modules/npm/bin/npm-cli.js": "// npm",
	} {
		file, err := writer.Create(name)
		if err != nil {
			t.Fatal(err)
		}
		file.Write([]byte(content))
	}
	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}
	archive := filepath.Join(t.TempDir(), "windows-amd64.zip")
	if err := os.WriteFile(archive, buffer.Bytes(), 0o644); err != nil {
		t.Fatal(err)
	}
	destination := t.TempDir()
	executable, err := node.Unpack(archive, destination)
	if err != nil {
		t.Fatal(err)
	}
	if executable != "node.exe" {
		t.Fatalf("executable = %q; want node.exe", executable)
	}
	for _, name := range []string{"node.exe", "npm.cmd", "node_modules/npm/bin/npm-cli.js"} {
		if _, err := os.Stat(filepath.Join(destination, filepath.FromSlash(name))); err != nil {
			t.Errorf("%s: %v", name, err)
		}
	}
}

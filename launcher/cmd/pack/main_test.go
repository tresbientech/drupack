package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// A Windows build passes a directory carrying a drive letter, which is why the
// separator is '=' and not ':'.
func TestRuntimeListReadsOneValue(t *testing.T) {
	cases := map[string]struct {
		value string
		want  packedRuntime
	}{
		"libc and directory":  {"glibc=/runtime/glibc", packedRuntime{libc: "glibc", directory: "/runtime/glibc"}},
		"directory alone":     {"/out", packedRuntime{directory: "/out"}},
		"windows drive alone": {`C:\out`, packedRuntime{directory: `C:\out`}},
	}
	for name, test := range cases {
		t.Run(name, func(t *testing.T) {
			var list runtimeList
			if err := list.Set(test.value); err != nil {
				t.Fatalf("Set(%q): %v", test.value, err)
			}
			if len(list) != 1 || list[0] != test.want {
				t.Fatalf("Set(%q) = %+v, want [%+v]", test.value, list, test.want)
			}
		})
	}
}

func TestRuntimeListKeepsTheOrderGiven(t *testing.T) {
	var list runtimeList
	for _, value := range []string{"glibc=/runtime/glibc", "musl=/runtime/musl"} {
		if err := list.Set(value); err != nil {
			t.Fatalf("Set(%q): %v", value, err)
		}
	}
	if len(list) != 2 || list[0].libc != "glibc" || list[1].libc != "musl" {
		t.Fatalf("Set twice = %+v, want glibc then musl", list)
	}
}

func TestRuntimeListRefusesAnEmptySide(t *testing.T) {
	for _, value := range []string{"=/runtime/glibc", "glibc="} {
		var list runtimeList
		if err := list.Set(value); err == nil {
			t.Fatalf("Set(%q) accepted a value naming only one side", value)
		}
	}
}

// buildRuntimes packs count directories, each holding one entry file, and returns
// what writeBuildCopy takes. A plain file carries no ELF interpreter, which is the
// shape every non-Linux entry has.
func buildRuntimes(t *testing.T, libcs ...string) []builtRuntime {
	t.Helper()
	built := make([]builtRuntime, 0, len(libcs))
	for _, libc := range libcs {
		directory := t.TempDir()
		if err := os.WriteFile(filepath.Join(directory, "drupack"), []byte("entry "+libc), 0700); err != nil {
			t.Fatal(err)
		}
		payload, manifest, err := runtime.Build(directory, "1.0.0", "drupack")
		if err != nil {
			t.Fatalf("Build(%s): %v", libc, err)
		}
		built = append(built, builtRuntime{libc: libc, payload: payload, manifest: manifest})
	}
	return built
}

// source stands in for the launcher source tree writeBuildCopy copies.
func source(t *testing.T) string {
	t.Helper()
	directory := t.TempDir()
	if err := os.WriteFile(filepath.Join(directory, "payload.go"), []byte("package main\n"), 0600); err != nil {
		t.Fatal(err)
	}
	return directory
}

func TestWriteBuildCopyCarriesOneRuntime(t *testing.T) {
	build := t.TempDir()
	built := buildRuntimes(t, "")
	if err := writeBuildCopy(build, source(t), built, site{name: "acme", version: "1.4.0"}); err != nil {
		t.Fatalf("writeBuildCopy: %v", err)
	}
	for _, name := range []string{"payload-0.tar.zst", "manifest-0.json", "payload.go"} {
		if _, err := os.Stat(filepath.Join(build, name)); err != nil {
			t.Fatalf("writeBuildCopy wrote no %s: %v", name, err)
		}
	}
	if _, err := os.Stat(filepath.Join(build, "payload-1.tar.zst")); err == nil {
		t.Fatal("writeBuildCopy wrote a second payload for one runtime")
	}
	generated := readFile(t, filepath.Join(build, "payload.go"))
	for _, want := range []string{
		"{libc: \"\", payload: payload0, manifest: manifest0},",
		"var siteName = \"acme\"",
		"var siteVersion = \"1.4.0\"",
	} {
		if !strings.Contains(generated, want) {
			t.Fatalf("payload.go does not carry %q:\n%s", want, generated)
		}
	}
}

func TestWriteBuildCopyCarriesEveryRuntimeInOrder(t *testing.T) {
	build := t.TempDir()
	built := buildRuntimes(t, "glibc", "musl")
	if err := writeBuildCopy(build, source(t), built, site{name: "acme", version: "1.4.0"}); err != nil {
		t.Fatalf("writeBuildCopy: %v", err)
	}
	for index, libc := range []string{"glibc", "musl"} {
		var m runtime.Manifest
		data := readFile(t, filepath.Join(build, "manifest-"+strconv.Itoa(index)+".json"))
		if err := json.Unmarshal([]byte(data), &m); err != nil {
			t.Fatalf("manifest-%d.json: %v", index, err)
		}
		if m.Entry != "drupack" {
			t.Fatalf("manifest-%d.json names entry %q", index, m.Entry)
		}
		if m.Interpreter != "" {
			t.Fatalf("manifest-%d.json records interpreter %q for a file that is no ELF", index, m.Interpreter)
		}
		if _, err := os.Stat(filepath.Join(build, "payload-"+strconv.Itoa(index)+".tar.zst")); err != nil {
			t.Fatalf("writeBuildCopy wrote no payload for %s: %v", libc, err)
		}
	}
	generated := readFile(t, filepath.Join(build, "payload.go"))
	glibcAt := strings.Index(generated, `{libc: "glibc", payload: payload0, manifest: manifest0},`)
	muslAt := strings.Index(generated, `{libc: "musl", payload: payload1, manifest: manifest1},`)
	if glibcAt < 0 || muslAt < 0 {
		t.Fatalf("payload.go does not carry both runtimes:\n%s", generated)
	}
	if glibcAt > muslAt {
		t.Fatal("payload.go lists musl before glibc, so the launcher would try the static runtime first")
	}
	for _, directive := range []string{"//go:embed payload-1.tar.zst", "//go:embed manifest-1.json"} {
		if !strings.Contains(generated, directive) {
			t.Fatalf("payload.go misses %q:\n%s", directive, generated)
		}
	}
}

// The two payloads differ, so the cache key Prepare mints differs, and one runtime
// never activates over the other's entry.
func TestEveryCarriedRuntimeMintsItsOwnCacheKey(t *testing.T) {
	built := buildRuntimes(t, "glibc", "musl")
	first := runtime.Key(built[0].manifest.Version, built[0].payload)
	second := runtime.Key(built[1].manifest.Version, built[1].payload)
	if first == second {
		t.Fatalf("both runtimes mint %s", first)
	}
	for _, key := range []string{first, second} {
		if !runtime.MintedSegment(key) {
			t.Fatalf("%s breaks %s", key, runtime.MintedSegmentPattern)
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

func TestTheEngineExecutableCarriesTheEngineMark(t *testing.T) {
	generated := embeddedPayloadSource(buildRuntimes(t, "glibc"), site{name: engineName, version: "0.5.0", engine: true})
	for _, want := range []string{"var siteName = \"drupack\"", "var siteVersion = \"0.5.0\"", "var engine = true"} {
		if !strings.Contains(generated, want) {
			t.Fatalf("payload.go does not carry %q:\n%s", want, generated)
		}
	}
}

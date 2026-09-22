package runtime_test

import (
	"debug/elf"
	"os"
	"path/filepath"
	goruntime "runtime"
	"strings"
	"testing"

	runtimepkg "git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// dynamicELF returns a host file that is an ELF carrying PT_INTERP, so Build has a
// real dynamic entry to read. A host whose candidates are all static skips the case.
func dynamicELF(t *testing.T) string {
	t.Helper()
	for _, candidate := range []string{"/bin/sh", "/bin/ls", "/usr/bin/env"} {
		file, err := elf.Open(candidate)
		if err != nil {
			continue
		}
		defer file.Close()
		for _, program := range file.Progs {
			if program.Type == elf.PT_INTERP {
				return candidate
			}
		}
	}
	t.Skip("this host has no dynamic ELF to copy")
	return ""
}

// The interpreter Build records is what Select stats to tell a host that runs the
// glibc runtime from one that does not.
func TestBuildRecordsTheEntryInterpreter(t *testing.T) {
	if goruntime.GOOS != "linux" {
		t.Skip("PT_INTERP is an ELF segment")
	}
	content, err := os.ReadFile(dynamicELF(t))
	if err != nil {
		t.Fatal(err)
	}
	directory := t.TempDir()
	if err := os.WriteFile(filepath.Join(directory, "drupack"), content, 0700); err != nil {
		t.Fatal(err)
	}
	_, manifest, err := runtimepkg.Build(directory, "1.0.0", "drupack")
	if err != nil {
		t.Fatalf("Build: %v", err)
	}
	if !strings.HasPrefix(manifest.Interpreter, "/") {
		t.Fatalf("Build recorded interpreter %q", manifest.Interpreter)
	}
}

// A static entry, and an entry of another format, both answer with no interpreter,
// so a launcher carrying one of them runs it on any host. An entry shorter than a
// magic number answers the same way.
func TestBuildRecordsNoInterpreterForANonELFEntry(t *testing.T) {
	for name, content := range map[string][]byte{
		"shorter than a magic number": []byte("hi"),
		"a Mach-O magic":              {0xcf, 0xfa, 0xed, 0xfe, 0x07, 0, 0, 1},
		"a PE magic":                  {'M', 'Z', 0x90, 0x00, 0x03, 0, 0, 0},
	} {
		t.Run(name, func(t *testing.T) {
			directory := t.TempDir()
			if err := os.WriteFile(filepath.Join(directory, "drupack"), content, 0700); err != nil {
				t.Fatal(err)
			}
			_, manifest, err := runtimepkg.Build(directory, "1.0.0", "drupack")
			if err != nil {
				t.Fatalf("Build: %v", err)
			}
			if manifest.Interpreter != "" {
				t.Fatalf("Build recorded interpreter %q", manifest.Interpreter)
			}
		})
	}
}

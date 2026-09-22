// Command pack builds a launcher carrying one runtime build per -runtime.
package main

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"github.com/klauspost/compress/zstd"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// packedRuntime names one runtime the launcher will carry: the libc it was
// linked against, and the directory holding its files. A value naming no libc
// is the sole runtime of a launcher that carries one, which the launcher runs
// without reading anything.
type packedRuntime struct {
	libc      string
	directory string
}

// runtimeList collects repeated -runtime values in the order given, which is
// the order the launcher tries them in. The separator is '=' rather than ':',
// since a Windows build passes a directory carrying a drive letter.
type runtimeList []packedRuntime

func (l *runtimeList) String() string {
	return fmt.Sprint([]packedRuntime(*l))
}

func (l *runtimeList) Set(value string) error {
	libc, directory, named := strings.Cut(value, "=")
	if !named {
		*l = append(*l, packedRuntime{directory: value})
		return nil
	}
	if libc == "" || directory == "" {
		return fmt.Errorf("-runtime takes LIBC=DIRECTORY or DIRECTORY, got %q", value)
	}
	*l = append(*l, packedRuntime{libc: libc, directory: directory})
	return nil
}

// builtRuntime is one packed runtime: its libc, its compressed payload and the
// manifest describing what the payload holds.
type builtRuntime struct {
	libc     string
	payload  []byte
	manifest runtime.Manifest
}

// embeddedPayloadSource returns the payload.go that replaces
// packaging/launcher's own in the build copy, so the launcher embeds this
// build's runtimes instead of the zero-value placeholder committed there.
func embeddedPayloadSource(built []builtRuntime) string {
	var source strings.Builder
	source.WriteString("package main\n\nimport _ \"embed\"\n")
	for index := range built {
		fmt.Fprintf(&source, "\n//go:embed payload-%d.tar.zst\nvar payload%d []byte\n", index, index)
		fmt.Fprintf(&source, "\n//go:embed manifest-%d.json\nvar manifest%d []byte\n", index, index)
	}
	source.WriteString("\nvar runtimes = []embeddedRuntime{\n")
	for index, one := range built {
		fmt.Fprintf(&source, "\t{libc: %q, payload: payload%d, manifest: manifest%d},\n", one.libc, index, index)
	}
	source.WriteString("}\n\n//go:embed app.tar.zst\nvar appPayload []byte\n\n//go:embed app_checksum.txt\nvar appChecksum []byte\n")
	return source.String()
}

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func run() error {
	var carried runtimeList
	flag.Var(&carried, "runtime", "runtime to pack, as LIBC=DIRECTORY, repeated for a launcher carrying more than one")
	version := flag.String("version", "", "runtime version")
	entry := flag.String("entry", "", "entry file, relative to -runtime")
	source := flag.String("source", "", "launcher source directory")
	output := flag.String("output", "", "path for the built launcher")
	app := flag.String("app", "", "application tar to carry")
	appChecksum := flag.String("app-checksum", "", "file holding the application checksum")
	flag.Parse()
	if err := requireFlags(map[string]string{
		"version":      *version,
		"entry":        *entry,
		"source":       *source,
		"output":       *output,
		"app":          *app,
		"app-checksum": *appChecksum,
	}); err != nil {
		return err
	}
	if len(carried) == 0 {
		return fmt.Errorf("-runtime is required")
	}

	built := make([]builtRuntime, 0, len(carried))
	for _, one := range carried {
		payload, manifest, err := runtime.Build(one.directory, *version, *entry)
		if err != nil {
			return err
		}
		if key := runtime.Key(*version, payload); !runtime.MintedSegment(key) {
			return fmt.Errorf("version %q would mint the cache entry %q, breaking the rule %s", *version, key, runtime.MintedSegmentPattern)
		}
		built = append(built, builtRuntime{libc: one.libc, payload: payload, manifest: manifest})
	}

	build, err := os.MkdirTemp("", "drupack-pack-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(build)

	if err := writeBuildCopy(build, *source, built); err != nil {
		return err
	}
	if err := writeAppPayload(build, *app, *appChecksum); err != nil {
		return err
	}

	outputPath, err := filepath.Abs(*output)
	if err != nil {
		return err
	}
	if err := buildLauncher(build, outputPath); err != nil {
		return err
	}

	info, err := os.Stat(outputPath)
	if err != nil {
		return err
	}
	for index, one := range built {
		fmt.Printf("runtime %d libc %q payload %d bytes\n", index, one.libc, len(one.payload))
	}
	fmt.Printf("output %d bytes\n", info.Size())
	return nil
}

func requireFlags(flags map[string]string) error {
	for name, value := range flags {
		if value == "" {
			return fmt.Errorf("-%s is required", name)
		}
	}
	return nil
}

// writeBuildCopy copies source into build, then adds every runtime's payload
// and manifest plus the embedding source, which together replace source's own
// payload.go in the copy that go build sees.
func writeBuildCopy(build, source string, built []builtRuntime) error {
	if err := copyTree(source, build); err != nil {
		return err
	}
	for index, one := range built {
		payloadName := fmt.Sprintf("payload-%d.tar.zst", index)
		if err := os.WriteFile(filepath.Join(build, payloadName), one.payload, 0600); err != nil {
			return err
		}
		manifestData, err := json.Marshal(one.manifest)
		if err != nil {
			return err
		}
		manifestName := fmt.Sprintf("manifest-%d.json", index)
		if err := os.WriteFile(filepath.Join(build, manifestName), manifestData, 0600); err != nil {
			return err
		}
	}
	return os.WriteFile(filepath.Join(build, "payload.go"), []byte(embeddedPayloadSource(built)), 0600)
}

// writeAppPayload compresses the application tar into the build copy, beside
// the checksum that names its cache entry.
func writeAppPayload(build, archive, checksumFile string) error {
	raw, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer raw.Close()
	out, err := os.OpenFile(filepath.Join(build, "app.tar.zst"), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0600)
	if err != nil {
		return err
	}
	compressor, err := zstd.NewWriter(out, zstd.WithEncoderLevel(zstd.SpeedBestCompression))
	if err != nil {
		out.Close()
		return err
	}
	if _, err := io.Copy(compressor, raw); err != nil {
		compressor.Close()
		out.Close()
		return err
	}
	if err := compressor.Close(); err != nil {
		out.Close()
		return err
	}
	if err := out.Close(); err != nil {
		return err
	}
	checksum, err := os.ReadFile(checksumFile)
	if err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(build, "app_checksum.txt"), bytes.TrimSpace(checksum), 0600)
}

func buildLauncher(build, output string) error {
	// go build keeps only the last -ldflags, so both linker flags share one value.
	command := exec.Command("go", "build", "-trimpath", "-ldflags=-s -w", "-o", output, ".")
	command.Dir = build
	command.Env = append(os.Environ(), "CGO_ENABLED=0")
	out, err := command.CombinedOutput()
	if err != nil {
		fmt.Fprint(os.Stderr, string(out))
		return err
	}
	return nil
}

func copyTree(source, destination string) error {
	return filepath.WalkDir(source, func(path string, entry fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		relative, err := filepath.Rel(source, path)
		if err != nil {
			return err
		}
		target := filepath.Join(destination, relative)
		if entry.IsDir() {
			return os.MkdirAll(target, 0700)
		}
		return copyFile(path, target)
	})
}

func copyFile(source, destination string) error {
	input, err := os.Open(source)
	if err != nil {
		return err
	}
	defer input.Close()
	info, err := input.Stat()
	if err != nil {
		return err
	}
	output, err := os.OpenFile(destination, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, info.Mode())
	if err != nil {
		return err
	}
	_, err = io.Copy(output, input)
	closeErr := output.Close()
	if err == nil {
		err = closeErr
	}
	return err
}

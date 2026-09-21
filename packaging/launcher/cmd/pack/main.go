// Command pack builds a launcher that carries one runtime build.
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

	"github.com/klauspost/compress/zstd"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// embeddedPayload replaces packaging/launcher's payload.go in the build copy,
// so the launcher embeds this build's payload and manifest instead of the
// zero-value placeholder committed at packaging/launcher/payload.go.
const embeddedPayload = `package main

import _ "embed"

//go:embed payload.tar.zst
var payload []byte

//go:embed manifest.json
var manifestData []byte

//go:embed app.tar.zst
var appPayload []byte

//go:embed app_checksum.txt
var appChecksum []byte
`

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func run() error {
	runtimeDir := flag.String("runtime", "", "directory holding the runtime to pack")
	version := flag.String("version", "", "runtime version")
	entry := flag.String("entry", "", "entry file, relative to -runtime")
	source := flag.String("source", "", "launcher source directory")
	output := flag.String("output", "", "path for the built launcher")
	app := flag.String("app", "", "application tar to carry")
	appChecksum := flag.String("app-checksum", "", "file holding the application checksum")
	flag.Parse()
	if err := requireFlags(map[string]string{
		"runtime":      *runtimeDir,
		"version":      *version,
		"entry":        *entry,
		"source":       *source,
		"output":       *output,
		"app":          *app,
		"app-checksum": *appChecksum,
	}); err != nil {
		return err
	}

	payload, manifest, err := runtime.Build(*runtimeDir, *version, *entry)
	if err != nil {
		return err
	}
	if key := runtime.Key(*version, payload); !runtime.MintedSegment(key) {
		return fmt.Errorf("version %q would mint the cache entry %q, breaking the rule %s", *version, key, runtime.MintedSegmentPattern)
	}

	build, err := os.MkdirTemp("", "drupack-pack-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(build)

	if err := writeBuildCopy(build, *source, payload, manifest); err != nil {
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
	fmt.Printf("payload %d bytes, output %d bytes\n", len(payload), info.Size())
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

// writeBuildCopy copies source into build, then adds this run's payload,
// manifest and embedding source, which together replace source's own
// payload.go in the copy that go build sees.
func writeBuildCopy(build, source string, payload []byte, manifest runtime.Manifest) error {
	if err := copyTree(source, build); err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(build, "payload.tar.zst"), payload, 0600); err != nil {
		return err
	}
	manifestData, err := json.Marshal(manifest)
	if err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(build, "manifest.json"), manifestData, 0600); err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(build, "payload.go"), []byte(embeddedPayload), 0600)
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

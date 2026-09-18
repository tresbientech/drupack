// Command pack builds a launcher that carries one runtime build.
package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"

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
	flag.Parse()
	if err := requireFlags(map[string]string{
		"runtime": *runtimeDir,
		"version": *version,
		"entry":   *entry,
		"source":  *source,
		"output":  *output,
	}); err != nil {
		return err
	}

	payload, manifest, err := runtime.Build(*runtimeDir, *version, *entry)
	if err != nil {
		return err
	}

	build, err := os.MkdirTemp("", "drupack-pack-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(build)

	if err := writeBuildCopy(build, *source, payload, manifest); err != nil {
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

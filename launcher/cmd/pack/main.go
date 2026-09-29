// Command pack builds a launcher carrying the runtime build -runtime names.
package main

import (
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	goruntime "runtime"

	"github.com/klauspost/compress/zstd"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/build"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/node"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// site names the packaged site and the release of it this launcher carries.
// engine marks the engine executable, which carries no site.
type site struct {
	name    string
	version string
	engine  bool
}

// embeddedPayloadSource returns the payload.go that replaces
// launcher's own in the build copy, so the launcher embeds this
// build's runtime instead of the zero-value placeholder committed there.
func embeddedPayloadSource(packaged site) string {
	return fmt.Sprintf(`package main

import _ "embed"

//go:embed runtime.tar.zst
var runtimePayload []byte

//go:embed manifest.json
var runtimeManifest []byte

//go:embed app.tar.zst
var appPayload []byte

//go:embed app_checksum.txt
var appChecksum []byte

//go:embed node.tar.zst
var nodePayload []byte

//go:embed node-manifest.json
var nodeManifest []byte

var siteName = %q

var siteVersion = %q

var engine = %t
`, packaged.name, packaged.version, packaged.engine)
}

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func run() error {
	directory := flag.String("runtime", "", "directory holding the runtime to pack")
	version := flag.String("version", "", "engine version, which names each runtime's cache entry")
	siteFile := flag.String("site", "", "the site.json of the site the application holds")
	siteVersion := flag.String("site-version", "", "the site's release")
	goarch := flag.String("goarch", goruntime.GOARCH, "architecture the launcher is built for")
	entry := flag.String("entry", "", "entry file, relative to -runtime")
	source := flag.String("source", "", "launcher source directory")
	output := flag.String("output", "", "path for the built launcher")
	app := flag.String("app", "", "application tar to carry")
	appChecksum := flag.String("app-checksum", "", "file holding the application checksum")
	engine := flag.Bool("engine", false, "pack the engine executable, whose -app holds the engine's files and whose release is -version")
	nodeArchive := flag.String("node", "", "a Node archive the build verified, carried as the release site.json names")
	flag.Parse()
	required := map[string]string{
		"runtime":      *directory,
		"version":      *version,
		"entry":        *entry,
		"source":       *source,
		"output":       *output,
		"app":          *app,
		"app-checksum": *appChecksum,
	}
	if !*engine {
		required["site"] = *siteFile
		required["site-version"] = *siteVersion
	}
	if err := requireFlags(required); err != nil {
		return err
	}
	packaged := site{name: build.EngineName, version: *version, engine: true}
	var nodeVersion siteconfig.Node
	if !*engine {
		// The build wrote site.json from a validated drupack.yml.
		var described siteconfig.Site
		content, err := os.ReadFile(*siteFile)
		if err != nil {
			return err
		}
		if err := json.Unmarshal(content, &described); err != nil {
			return fmt.Errorf("%s: %w", *siteFile, err)
		}
		packaged = site{name: described.Name, version: *siteVersion}
		nodeVersion = described.Node
	}

	payload, manifest, err := runtime.Build(*directory, *version, *entry)
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

	if err := writeBuildCopy(build, *source, payload, manifest, packaged); err != nil {
		return err
	}
	if err := writeNodePayload(build, *nodeArchive, string(nodeVersion)); err != nil {
		return err
	}
	if err := writeAppPayload(build, *app, *appChecksum); err != nil {
		return err
	}

	outputPath, err := filepath.Abs(*output)
	if err != nil {
		return err
	}
	if err := buildLauncher(build, outputPath, *goarch); err != nil {
		return err
	}

	info, err := os.Stat(outputPath)
	if err != nil {
		return err
	}
	fmt.Printf("runtime payload %d bytes\n", len(payload))
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

// writeBuildCopy copies source into directory, then adds the runtime's payload
// and manifest plus the embedding source, which together replace source's own
// payload.go in the copy that go build sees.
func writeBuildCopy(directory, source string, payload []byte, manifest runtime.Manifest, packaged site) error {
	if err := build.CopyTree(source, directory, func(string) bool { return false }); err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(directory, "runtime.tar.zst"), payload, 0600); err != nil {
		return err
	}
	manifestData, err := json.Marshal(manifest)
	if err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(directory, "manifest.json"), manifestData, 0600); err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(directory, "payload.go"), []byte(embeddedPayloadSource(packaged)), 0600)
}

// writeNodePayload packs the Node release in archive into the build copy, or
// leaves both embedded files empty when archive is unset.
func writeNodePayload(build, archive, version string) error {
	var payload, manifestData []byte
	if archive != "" {
		tree, err := os.MkdirTemp("", "drupack-node-")
		if err != nil {
			return err
		}
		defer os.RemoveAll(tree)
		executable, err := node.Unpack(archive, tree)
		if err != nil {
			return err
		}
		var manifest runtime.Manifest
		if payload, manifest, err = runtime.Build(tree, version, executable); err != nil {
			return err
		}
		if manifestData, err = json.Marshal(manifest); err != nil {
			return err
		}
		fmt.Printf("Node %s payload %d bytes\n", version, len(payload))
	}
	if err := os.WriteFile(filepath.Join(build, "node.tar.zst"), payload, 0600); err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(build, "node-manifest.json"), manifestData, 0600)
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

func buildLauncher(build, output, goarch string) error {
	// go build keeps only the last -ldflags, so both linker flags share one value.
	// The build copy sits in the temporary directory and is no checkout, so a .git
	// directory above it would only make VCS stamping fail.
	command := exec.Command("go", "build", "-trimpath", "-buildvcs=false", "-ldflags=-s -w", "-o", output, ".")
	command.Dir = build
	command.Env = append(os.Environ(), "CGO_ENABLED=0", "GOARCH="+goarch)
	out, err := command.CombinedOutput()
	if err != nil {
		fmt.Fprint(os.Stderr, string(out))
		return err
	}
	return nil
}

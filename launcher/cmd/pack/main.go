// Command pack builds a launcher carrying the runtime build -runtime names.
package main

import (
	"bytes"
	"cmp"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/build"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/node"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// site names the packaged site and the release of it this launcher carries.
// components lists the site's parts --version names. engine marks the engine
// executable, which carries no site. musl marks a file packed over a musl runtime.
type site struct {
	name       string
	version    string
	components []string
	engine     bool
	musl       bool
}

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

var siteComponents = %q

var engine = %t

var musl = %t
`, packaged.name, packaged.version, strings.Join(packaged.components, "\n"), packaged.engine, packaged.musl)
}

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

type options struct {
	target, runtime, payload, version, siteVersion, output string
	engine                                                 bool
}

// packing is what pack resolved from its options and the payload directory. nodeArchive
// is empty for a target that carries no Node.
type packing struct {
	options
	arch, entry, libc, checksum, nodeArchive, nodeVersion string
	site                                                  site
}

func run() error {
	var o options
	flag.StringVar(&o.target, "target", "", "what the file runs on, a targets.json platform and its libc suffix, such as windows-amd64")
	flag.StringVar(&o.runtime, "runtime", "", "directory holding the runtime to pack")
	flag.StringVar(&o.payload, "payload", "", "directory holding the application payload, its checksum, the site.json and the Node archives")
	flag.StringVar(&o.version, "version", "", "engine version, which names each runtime's cache entry")
	flag.StringVar(&o.siteVersion, "site-version", "", "the site's release, -version when unset")
	flag.StringVar(&o.output, "output", "", "path for the built launcher")
	flag.BoolVar(&o.engine, "engine", false, "pack the engine executable, which carries no site and whose release is -version")
	flag.Parse()
	if err := requireFlags(map[string]string{"target": o.target, "runtime": o.runtime, "payload": o.payload, "version": o.version, "output": o.output}); err != nil {
		return err
	}
	packed, err := resolve(o)
	if err != nil {
		return err
	}
	printInputs(os.Stdout, packed)

	payload, manifest, err := runtime.Build(o.runtime, o.version, packed.entry)
	if err != nil {
		return err
	}
	if key := runtime.Key(o.version, payload); !runtime.MintedSegment(key) {
		return fmt.Errorf("version %q would mint the cache entry %q, breaking the rule %s", o.version, key, runtime.MintedSegmentPattern)
	}
	build, err := os.MkdirTemp("", "drupack-pack-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(build)
	if err := writeBuildCopy(build, ".", payload, manifest, packed); err != nil {
		return err
	}

	outputPath, err := filepath.Abs(o.output)
	if err != nil {
		return err
	}
	if err := buildLauncher(build, outputPath, packed.arch); err != nil {
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

// parseTarget splits target into a targets.json platform, its architecture and its libc.
func parseTarget(target string) (platform, arch, libc string, err error) {
	for candidate, goarch := range runtime.Platforms {
		for _, known := range runtime.Libcs {
			if runtime.Target(candidate, known.Name) == target {
				return candidate, goarch, known.Name, nil
			}
		}
	}
	return "", "", "", fmt.Errorf("target %q is not a platform of targets.json with a libc suffix", target)
}

// entryName is the runtime's entry file: the Windows runtime starts from frankenphp.exe.
func entryName(platform string) string {
	if strings.HasPrefix(platform, "windows-") {
		return "frankenphp.exe"
	}
	return "drupack"
}

// resolve reads the payload directory o names and decides what the target carries.
func resolve(o options) (packing, error) {
	platform, arch, libc, err := parseTarget(o.target)
	if err != nil {
		return packing{}, err
	}
	checksum, err := os.ReadFile(filepath.Join(o.payload, build.ChecksumName))
	if err != nil {
		return packing{}, err
	}
	p := packing{options: o, arch: arch, entry: entryName(platform), libc: libc, checksum: string(bytes.TrimSpace(checksum))}
	p.site = site{name: build.EngineName, version: o.version, engine: true, musl: libc == "musl"}
	if o.engine {
		return p, nil
	}
	described, err := siteconfig.Load(o.payload)
	if err != nil {
		return packing{}, err
	}
	if archive, carried := node.Archive(filepath.Join(o.payload, build.NodeDirName), o.target); described.Node != "" && carried {
		if _, err := os.Stat(archive); err != nil {
			return packing{}, err
		}
		p.nodeArchive = archive
	}
	p.nodeVersion = string(described.Node)
	p.site = site{name: described.Name, version: cmp.Or(o.siteVersion, o.version), components: siteComponents(described, p.nodeArchive != ""), musl: libc == "musl"}
	return p, nil
}

// printInputs prints one line per input, so a wrongly packed file is diagnosed from the build log.
func printInputs(w io.Writer, p packing) {
	fmt.Fprintf(w, "target %s\narchitecture %s\nentry %s\nlibc %s\npayload %s\napplication checksum %s\nNode archive %s\nsite %s\nsite version %s\nengine version %s\n",
		p.target, p.arch, p.entry, p.libc, p.payload, p.checksum, cmp.Or(p.nodeArchive, "none"), p.site.name, p.site.version, p.version)
}

func siteComponents(described siteconfig.Site, node bool) []string {
	var lines []string
	if node {
		lines = append(lines, "Node "+string(described.Node))
	}
	return append(lines, "Drupal "+described.Drupal, "Drush "+described.Drush)
}

func requireFlags(flags map[string]string) error {
	for name, value := range flags {
		if value == "" {
			return fmt.Errorf("-%s is required", name)
		}
	}
	return nil
}

// writeBuildCopy lays the launcher source, the runtime and the payload of p into directory.
func writeBuildCopy(directory, source string, payload []byte, manifest runtime.Manifest, p packing) error {
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
	if err := os.WriteFile(filepath.Join(directory, "payload.go"), []byte(embeddedPayloadSource(p.site)), 0600); err != nil {
		return err
	}
	if err := writeNodePayload(directory, p.nodeArchive, p.nodeVersion); err != nil {
		return err
	}
	return writeAppPayload(directory, filepath.Join(p.payload, build.PayloadName), p.checksum)
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

// writeAppPayload copies the compressed application archive into the build copy.
func writeAppPayload(build, archive, checksum string) error {
	payload, err := os.ReadFile(archive)
	if err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(build, "app.tar.zst"), payload, 0600); err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(build, "app_checksum.txt"), []byte(checksum), 0600)
}

func buildLauncher(build, output, goarch string) error {
	// go build keeps only the last -ldflags, so both linker flags share one value.
	// The build copy is no checkout, so VCS stamping would fail.
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

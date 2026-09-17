package main

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	_ "embed"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

//go:embed runtime.payload
var runtimeArchive string

type manifest struct {
	Version string         `json:"version"`
	Files   []manifestFile `json:"files"`
}

type manifestFile struct {
	Path   string `json:"path"`
	SHA256 string `json:"sha256"`
}

//go:embed runtime-manifest.json
var runtimeManifest string

const dataDirectoryError = "--data-dir requires a path"
const runtimeExecutable = "frankenphp.exe"
const storedManifestName = "runtime-manifest.json"

func main() {
	data, args, err := invocation(os.Args[1:])
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(2)
	}

	runtime, err := prepareRuntime(data)
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}

	command := exec.Command(filepath.Join(runtime, runtimeExecutable), args...)
	command.Stdin = os.Stdin
	command.Stdout = os.Stdout
	command.Stderr = os.Stderr
	// php.ini in the runtime directory locates its extensions through PHPRC.
	command.Env = append(os.Environ(), "DRUPACK_DATA_DIR="+data, "PHPRC="+runtime)
	if err := command.Run(); err != nil {
		var exitError *exec.ExitError
		if errors.As(err, &exitError) {
			os.Exit(exitError.ExitCode())
		}
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

// invocation removes --data-dir from the arguments. main passes the chosen
// directory to the runtime as DRUPACK_DATA_DIR, so launch.php uses the same one.
func invocation(args []string) (string, []string, error) {
	data := os.Getenv("DRUPACK_DATA_DIR")
	if data == "" {
		data = "./data"
	}
	forwarded := make([]string, 0, len(args))
	for index := 0; index < len(args); index++ {
		if args[index] == "--data-dir" {
			if index+1 == len(args) {
				return "", nil, errors.New(dataDirectoryError)
			}
			data = args[index+1]
			index++
			continue
		}
		if value, found := strings.CutPrefix(args[index], "--data-dir="); found {
			if value == "" {
				return "", nil, errors.New(dataDirectoryError)
			}
			data = value
			continue
		}
		forwarded = append(forwarded, args[index])
	}
	return data, forwarded, nil
}

func prepareRuntime(data string) (string, error) {
	root, err := filepath.Abs(filepath.Join(data, "runtime"))
	if err != nil {
		return "", err
	}
	if err := os.MkdirAll(root, 0700); err != nil {
		return "", err
	}
	manifest, archive, err := bundledRuntime()
	if err != nil {
		return "", err
	}
	active, activeErr := activeRuntime(root)
	target := filepath.Join(root, manifest.Version)
	if err := validateRuntime(target, manifest); err == nil {
		if err := activate(root, manifest.Version); err != nil {
			return "", err
		}
		return target, nil
	}
	if err := stageRuntime(root, target, archive, manifest); err != nil {
		if activeErr == nil {
			fmt.Fprintf(os.Stderr, "Warning: could not install bundled runtime: %v. Using the previous runtime.\n", err)
			return active, nil
		}
		return "", fmt.Errorf("could not install bundled runtime: %w", err)
	}
	return target, nil
}

func bundledRuntime() (manifest, []byte, error) {
	var runtime manifest
	if err := json.Unmarshal([]byte(runtimeManifest), &runtime); err != nil {
		return runtime, nil, fmt.Errorf("invalid runtime manifest: %w", err)
	}
	if err := validManifest(runtime); err != nil {
		return runtime, nil, err
	}
	archive, err := base64.StdEncoding.DecodeString(runtimeArchive)
	if err != nil {
		return runtime, nil, fmt.Errorf("invalid runtime archive: %w", err)
	}
	return runtime, archive, nil
}

func activeRuntime(root string) (string, error) {
	contents, err := os.ReadFile(filepath.Join(root, "active"))
	if err != nil {
		return "", err
	}
	version := strings.TrimSpace(string(contents))
	if version == "" || filepath.Base(version) != version {
		return "", errors.New("invalid active runtime")
	}
	runtime := filepath.Join(root, version)
	stored, err := storedManifest(runtime)
	if err != nil {
		return "", err
	}
	if err := validateRuntime(runtime, stored); err != nil {
		return "", err
	}
	return runtime, nil
}

func stageRuntime(root, target string, archive []byte, runtime manifest) error {
	staging, err := os.MkdirTemp(root, runtime.Version+".staging-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(staging)
	if err := extractArchive(staging, archive, runtime); err != nil {
		return err
	}
	if err := validateRuntime(staging, runtime); err != nil {
		return err
	}
	if err := writeManifest(staging, runtime); err != nil {
		return err
	}
	if _, err := os.Stat(target); err == nil {
		invalid := fmt.Sprintf("%s.invalid-%d", target, os.Getpid())
		if err := os.Rename(target, invalid); err != nil {
			return err
		}
	}
	if err := os.Rename(staging, target); err != nil {
		return err
	}
	if err := activate(root, runtime.Version); err != nil {
		return err
	}
	return nil
}

func extractArchive(destination string, archive []byte, runtime manifest) error {
	reader, err := zip.NewReader(bytes.NewReader(archive), int64(len(archive)))
	if err != nil {
		return err
	}
	declared := make(map[string]manifestFile, len(runtime.Files))
	for _, file := range runtime.Files {
		declared[file.Path] = file
	}
	for _, file := range reader.File {
		entry, found := declared[file.Name]
		if !found {
			return fmt.Errorf("runtime archive contains undeclared file: %s", file.Name)
		}
		path := filepath.Join(destination, filepath.FromSlash(entry.Path))
		if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
			return err
		}
		input, err := file.Open()
		if err != nil {
			return err
		}
		output, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0700)
		if err == nil {
			_, err = io.Copy(output, input)
			closeErr := output.Close()
			if err == nil {
				err = closeErr
			}
		}
		input.Close()
		if err != nil {
			return err
		}
	}
	return nil
}

func writeManifest(directory string, runtime manifest) error {
	contents, err := json.Marshal(runtime)
	if err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(directory, storedManifestName), contents, 0600)
}

func storedManifest(directory string) (manifest, error) {
	var runtime manifest
	contents, err := os.ReadFile(filepath.Join(directory, storedManifestName))
	if err != nil {
		return runtime, err
	}
	if err := json.Unmarshal(contents, &runtime); err != nil {
		return runtime, err
	}
	if err := validManifest(runtime); err != nil {
		return runtime, err
	}
	return runtime, nil
}

// validManifest checks a manifest before any of its paths are used. The stored
// copy lives in Site data, where the site owner can change it.
func validManifest(runtime manifest) error {
	if runtime.Version == "" || filepath.Base(runtime.Version) != runtime.Version || len(runtime.Files) == 0 {
		return errors.New("invalid runtime manifest")
	}
	for _, file := range runtime.Files {
		if !safePath(file.Path) {
			return fmt.Errorf("runtime manifest contains an unsafe path: %s", file.Path)
		}
		if file.SHA256 == "" {
			return fmt.Errorf("runtime manifest has no checksum for %s", file.Path)
		}
	}
	return nil
}

func validateRuntime(directory string, runtime manifest) error {
	for _, file := range runtime.Files {
		if !safePath(file.Path) {
			return errors.New("runtime manifest contains an unsafe path")
		}
		contents, err := os.ReadFile(filepath.Join(directory, filepath.FromSlash(file.Path)))
		if err != nil {
			return err
		}
		hash := sha256.Sum256(contents)
		if hex.EncodeToString(hash[:]) != strings.ToLower(file.SHA256) {
			return fmt.Errorf("runtime checksum mismatch: %s", file.Path)
		}
	}
	_, err := os.Stat(filepath.Join(directory, runtimeExecutable))
	return err
}

func activate(root, version string) error {
	pending := filepath.Join(root, "active.pending")
	if err := os.WriteFile(pending, []byte(version+"\n"), 0600); err != nil {
		return err
	}
	return os.Rename(pending, filepath.Join(root, "active"))
}

func safePath(path string) bool {
	clean := filepath.Clean(filepath.FromSlash(path))
	return path != "" && !filepath.IsAbs(clean) && clean != "." && !strings.HasPrefix(clean, ".."+string(filepath.Separator)) && clean != ".."
}

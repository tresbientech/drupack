package main

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	_ "embed"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strings"
	"syscall"
	"time"
	"unsafe"
)

//go:embed runtime.zip
var runtimeArchive []byte

type manifest struct {
	Version string         `json:"version"`
	Files   []manifestFile `json:"files"`
}

type manifestFile struct {
	Path   string `json:"path"`
	SHA256 string `json:"sha256"`
	Size   int64  `json:"size"`
}

//go:embed runtime-manifest.json
var runtimeManifest string

const runtimeExecutable = "frankenphp.exe"
const storedManifestName = "runtime-manifest.json"
const activeFileName = "active"
const lockFileName = "lock"
const lockTimeout = 60 * time.Second
const lockPollInterval = 100 * time.Millisecond

func main() {
	runtime, err := prepareRuntime()
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}

	command := exec.Command(filepath.Join(runtime, runtimeExecutable), os.Args[1:]...)
	command.Stdin = os.Stdin
	command.Stdout = os.Stdout
	command.Stderr = os.Stderr
	// php.ini in the runtime directory locates its extensions through PHPRC.
	command.Env = append(os.Environ(), "PHPRC="+runtime)
	owned := consoleOwned()
	if owned {
		command.Env = append(command.Env, "DRUPACK_RUNTIME_CONSOLE_OWNED=1")
	}
	if err := command.Run(); err != nil {
		var exitError *exec.ExitError
		if !errors.As(err, &exitError) {
			fmt.Fprintln(os.Stderr, err)
			waitForReader(owned)
			os.Exit(1)
		}
		waitForReader(owned)
		os.Exit(exitError.ExitCode())
	}
}

// consoleOwned reports whether this process is alone on its console, which means
// a file manager created the window. A console from a shell also holds the shell.
func consoleOwned() bool {
	var process uint32
	count, _, _ := syscall.NewLazyDLL("kernel32.dll").NewProc("GetConsoleProcessList").
		Call(uintptr(unsafe.Pointer(&process)), 1)
	return count == 1
}

// waitForReader keeps a file manager window open, so its reader sees the failure.
func waitForReader(owned bool) {
	if !owned {
		return
	}
	fmt.Fprint(os.Stderr, "Press Enter to close this window.")
	fmt.Fscanln(os.Stdin)
}

// prepareRuntime extracts the bundled runtime under the user's cache directory,
// %LOCALAPPDATA% on Windows, so a failed start writes no Site data.
func prepareRuntime() (string, error) {
	cache, err := os.UserCacheDir()
	if err != nil {
		return "", err
	}
	root := filepath.Join(cache, "Drupack", "runtime")
	if err := os.MkdirAll(root, 0700); err != nil {
		return "", err
	}
	manifest, err := bundledManifest()
	if err != nil {
		return "", err
	}
	target := filepath.Join(root, manifest.Version)
	if installed, err := activateInstalled(root, target, manifest); err == nil {
		return installed, nil
	}
	return installRuntime(root, target, manifest)
}

// activateInstalled activates target when it already matches runtime, without
// taking the cache lock: a repeat start of one version cannot corrupt another
// process's pending activation, because each pending file name is its own.
func activateInstalled(root, target string, runtime manifest) (string, error) {
	if err := installedRuntime(target, runtime); err != nil {
		return "", err
	}
	if err := activate(root, runtime.Version); err != nil {
		return "", err
	}
	return target, nil
}

// installRuntime serializes installation and activation with a lock on the
// cache root, shared by every Site's launcher. A launch that cannot take the
// lock within the timeout runs the active runtime instead, since a stuck
// holder must not wedge every later start.
func installRuntime(root, target string, runtime manifest) (string, error) {
	handle, err := acquireLock(filepath.Join(root, lockFileName), lockTimeout)
	if err != nil {
		if active, activeErr := activeRuntime(root); activeErr == nil {
			return active, nil
		}
		return "", fmt.Errorf("could not lock the runtime cache %s: %w", root, err)
	}
	defer syscall.CloseHandle(handle)

	// Another process may have installed this version while this one waited.
	if installed, err := activateInstalled(root, target, runtime); err == nil {
		return installed, nil
	}
	if err := stageRuntime(root, target, runtimeArchive, runtime); err != nil {
		if active, activeErr := activeRuntime(root); activeErr == nil {
			fmt.Fprintf(os.Stderr, "Warning: could not install bundled runtime: %v. Using the previous runtime.\n", err)
			return active, nil
		}
		return "", fmt.Errorf("could not install bundled runtime: %w", err)
	}
	return target, nil
}

// acquireLock opens an exclusive handle to path, retrying until timeout. The
// OS releases the handle if this process dies, so a crash leaves no stale lock.
func acquireLock(path string, timeout time.Duration) (syscall.Handle, error) {
	pointer, err := syscall.UTF16PtrFromString(path)
	if err != nil {
		return syscall.InvalidHandle, err
	}
	deadline := time.Now().Add(timeout)
	for {
		handle, err := syscall.CreateFile(pointer, syscall.GENERIC_READ|syscall.GENERIC_WRITE, 0, nil, syscall.OPEN_ALWAYS, syscall.FILE_ATTRIBUTE_NORMAL, 0)
		if err == nil {
			return handle, nil
		}
		if time.Now().After(deadline) {
			return syscall.InvalidHandle, err
		}
		time.Sleep(lockPollInterval)
	}
}

func bundledManifest() (manifest, error) {
	var runtime manifest
	if err := json.Unmarshal([]byte(runtimeManifest), &runtime); err != nil {
		return runtime, fmt.Errorf("invalid runtime manifest: %w", err)
	}
	return runtime, validManifest(runtime)
}

func activeRuntime(root string) (string, error) {
	contents, err := os.ReadFile(filepath.Join(root, activeFileName))
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
	if err := checkSizes(runtime, stored); err != nil {
		return "", err
	}
	return runtime, nil
}

// installedRuntime checks a runtime installed by stageRuntime, which hashed every
// file. A start compares the stored manifest and file sizes instead of hashing again.
func installedRuntime(directory string, runtime manifest) error {
	stored, err := storedManifest(directory)
	if err != nil {
		return err
	}
	if !reflect.DeepEqual(stored, runtime) {
		return errors.New("installed runtime differs from the bundled runtime")
	}
	return checkSizes(directory, stored)
}

func checkSizes(directory string, runtime manifest) error {
	for _, file := range runtime.Files {
		info, err := os.Stat(filepath.Join(directory, filepath.FromSlash(file.Path)))
		if err != nil {
			return err
		}
		if info.Size() != file.Size {
			return fmt.Errorf("runtime file size changed: %s", file.Path)
		}
	}
	return nil
}

func stageRuntime(root, target string, archive []byte, runtime manifest) error {
	removeInvalidDirectories(target)
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

// removeInvalidDirectories clears targets a previous install renamed out of
// the way. One still open elsewhere is left for a later install to retry.
func removeInvalidDirectories(target string) {
	matches, _ := filepath.Glob(target + ".invalid-*")
	for _, match := range matches {
		os.RemoveAll(match)
	}
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
// copy lives in the user's cache directory, where the user can change it.
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
	pending := filepath.Join(root, fmt.Sprintf("%s.pending.%d", activeFileName, os.Getpid()))
	if err := os.WriteFile(pending, []byte(version+"\n"), 0600); err != nil {
		return err
	}
	return os.Rename(pending, filepath.Join(root, activeFileName))
}

func safePath(path string) bool {
	clean := filepath.Clean(filepath.FromSlash(path))
	return path != "" && !filepath.IsAbs(clean) && clean != "." && !strings.HasPrefix(clean, ".."+string(filepath.Separator)) && clean != ".."
}

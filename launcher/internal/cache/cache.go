package cache

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"time"
	"unicode/utf8"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/fsguard"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/payload"
)

// stagingPrefix starts the name of a directory being written or removed.
// Cleanup matches on it to clear what an interrupted run left.
const stagingPrefix = ".staging-"

func asideName(path string) string {
	return filepath.Join(filepath.Dir(path), stagingPrefix+"removing-"+filepath.Base(path))
}

const (
	activeName = "active"
	lockName   = "lock"
)

// holdAttempts and holdRetryInterval bound how long HoldUsage waits out an
// entryInUse test of the same marker, which briefly locks it exclusively.
const (
	holdAttempts      = 50
	holdRetryInterval = 10 * time.Millisecond
)

// usageName lives inside one entry, runtime or application, and carries the
// lock a running start holds on the files it serves from. Cleanup tests it
// before removing anything.
const usageName = ".inuse"

// Root returns the cache directory a runtime unpacks into, creating it. A user-named
// directory that refuses writes is reported, never swapped for another. A refused candidate
// before the one used gets one line to notice. On Windows, a root
// PHP cannot read as ASCII falls to the temporary directory with one line to notice; a root
// with no ASCII form anywhere stops the start.
func Root(name string, notice io.Writer) (string, error) {
	if dir := os.Getenv("DRUPACK_CACHE_DIR"); dir != "" {
		root, err := claimRoot(dir)
		if err != nil {
			return "", err
		}
		return asciiRoot(root, name, notice)
	}

	var refused []error
	for _, candidate := range cacheRoots(name) {
		root, err := claimCandidate(candidate)
		if err == nil {
			for _, earlier := range refused {
				fmt.Fprintf(notice, "%s. Using %s instead.\n", earlier, root)
			}
			return asciiRoot(root, name, notice)
		}
		refused = append(refused, err)
	}
	// Windows lists no candidate when it names no local application data folder.
	if refused == nil {
		return "", errors.New("no cache directory to unpack into: set DRUPACK_CACHE_DIR")
	}
	return "", refused[len(refused)-1]
}

// claimRoot creates dir and returns its real path once no other account owns
// it or can open it, since a launch runs what the root holds. A root that fails
// is refused rather than repaired.
func claimRoot(dir string) (string, error) {
	if err := fsguard.Create(dir); err != nil {
		return "", err
	}
	root, err := filepath.Abs(dir)
	if err != nil {
		return "", err
	}
	if root, err = filepath.EvalSymlinks(root); err != nil {
		return "", err
	}
	if err := refuseExposed(root, true); err != nil {
		return "", err
	}
	return root, nil
}

// claimCandidate claims a root this package named, whose parent is the
// per-account folder Create makes with it. A temporary directory lets another
// account make that folder first and swap the root inside it, so the folder
// must be this user's too.
func claimCandidate(dir string) (string, error) {
	root, err := claimRoot(dir)
	if err != nil {
		return "", err
	}
	return root, refuseExposed(filepath.Dir(root), false)
}

func refuseExposed(dir string, readers bool) error {
	exposed, err := fsguard.Exposure(dir, readers)
	if err != nil {
		return fmt.Errorf("cannot check who can change the cache folder %s: %w", dir, err)
	}
	if exposed != nil {
		return fmt.Errorf("cache folder %s refused: %w. Remove that access, or set DRUPACK_CACHE_DIR "+
			"to a directory that only you can change", dir, exposed)
	}
	return nil
}

// resolveASCIIRoot returns the first candidate PHP startup can locate: root, then fallback.
// PHP resolves its PHPRC-derived configuration path through the ANSI code page, so a root
// outside it breaks extension loading. claim creates and checks a candidate and returns the path
// it checked, which the rest goes on from. It runs first because Windows allocates a short name
// only for a path that exists. An ASCII candidate is returned untouched, since resolve also
// rewrites a long ASCII segment. Falling to fallback writes one line to notice. Two exhausted
// candidates stop the start, since a site missing its extensions is worse than a refused start.
//
//nolint:unused // root_windows.go calls it. The shared file lets the Linux tests run it.
func resolveASCIIRoot(root, fallback string, claim func(string) (string, error), resolve func(string) (string, error), notice io.Writer) (string, error) {
	ascii, rootErr := asciiForm(root, claim, resolve)
	if rootErr == nil {
		return ascii, nil
	}

	ascii, fallbackErr := asciiForm(fallback, claim, resolve)
	if fallbackErr == nil {
		fmt.Fprintf(notice, "Could not use cache root %s: %s. Using %s instead.\n", root, rootErr, fallback)
		return ascii, nil
	}

	return "", fmt.Errorf(
		"cache root %s (%s) and fallback %s (%s) have no ASCII path PHP can load extensions from: set DRUPACK_CACHE_DIR to an ASCII directory",
		root, rootErr, fallback, fallbackErr,
	)
}

// asciiForm claims candidate, then returns the claimed path when ASCII, or its resolved
// short name when that is ASCII.
//
//nolint:unused // resolveASCIIRoot calls it.
func asciiForm(candidate string, claim func(string) (string, error), resolve func(string) (string, error)) (string, error) {
	candidate, err := claim(candidate)
	if err != nil {
		return "", err
	}
	if isASCII(candidate) {
		return candidate, nil
	}
	resolved, err := resolve(candidate)
	if err != nil {
		return "", err
	}
	if !isASCII(resolved) {
		return "", fmt.Errorf("resolved to %s, still not ASCII", resolved)
	}
	return resolved, nil
}

//nolint:unused // resolveASCIIRoot calls it.
func isASCII(s string) bool {
	for i := 0; i < len(s); i++ {
		if s[i] >= utf8.RuneSelf {
			return false
		}
	}
	return true
}

// Key names the cache entry for a version and its payload, so a payload
// change without a version bump still lands in its own entry.
func Key(version string, payload []byte) string {
	sum := sha256.Sum256(payload)
	return "v" + version + "-" + hex.EncodeToString(sum[:])[:12]
}

// Prepare returns the directory holding the runtime m describes, staging payload the first
// time. It activates nothing: Activate does, once the application is ready too. A start that
// cannot stage its runtime stops, since the application beside it belongs to this release alone.
func (c Cache) Prepare(payload []byte, m payload.Manifest, notice io.Writer) (string, error) {
	sp, err := releaseSpec(c.root, "runtime", payload, m, notice)
	if err != nil {
		return "", err
	}
	return c.runtimes().prepare(sp, notice)
}

// releaseSpec describes the entry for a runtime or Node release, named by Key. A checksum
// failure leaves the cache untouched: the entry takes its name only after every file verifies.
func releaseSpec(dir, what string, archive []byte, m payload.Manifest, notice io.Writer) (spec, error) {
	contents, err := json.Marshal(m)
	if err != nil {
		return spec{}, err
	}
	return spec{
		name:   Key(m.Version, archive),
		notice: fmt.Sprintf("Unpacking %s %s. This happens once for each version.", what, m.Version),
		ready:  func(entry string) bool { return warm(entry, m) },
		fill: func(staging string) error {
			err := payload.Extract(staging, archive, m, notice)
			if err == nil {
				err = verifyChecksums(staging, m)
			}
			if err != nil {
				return stagingFailure(dir, m, err)
			}
			return nil
		},
		marker:   payload.ManifestName,
		contents: contents,
	}, nil
}

// Activate names entry, a directory Prepare returned, as the active runtime
// and removes every other version's entry. An entry already active changes
// nothing, so a warm start takes no lock.
func (c Cache) Activate(entry string, notice io.Writer) error {
	root := c.root
	name := filepath.Base(entry)
	if active, err := activeKey(root); err == nil && active == name {
		return nil
	}
	unlock, err := lockRoot(root, notice)
	if err != nil {
		return fmt.Errorf("could not lock the runtime cache %s: %w", root, err)
	}
	defer unlock()
	if err := writeActive(root, name); err != nil {
		return err
	}
	removeOthers(root, name)
	return nil
}

func warm(entry string, m payload.Manifest) bool {
	stored, err := readManifest(entry)
	if err != nil {
		return false
	}
	if !reflect.DeepEqual(stored, m) {
		return false
	}
	return sizesMatch(entry, m)
}

func readManifest(entry string) (payload.Manifest, error) {
	data, err := os.ReadFile(filepath.Join(entry, payload.ManifestName))
	if err != nil {
		return payload.Manifest{}, err
	}
	return payload.ParseManifest(data)
}

func sizesMatch(entry string, m payload.Manifest) bool {
	for _, file := range m.Files {
		info, err := os.Stat(filepath.Join(entry, filepath.FromSlash(file.Path)))
		if err != nil || info.Size() != file.Size {
			return false
		}
	}
	return true
}

// removeOthers deletes the other versions' entries. An entry another start still serves from
// stays, since removal would pull PHP files from under a running site. A removal failure
// reports nothing; the next start retries.
func removeOthers(root, key string) {
	entries, err := os.ReadDir(root)
	if err != nil {
		return
	}
	for _, candidate := range entries {
		path := filepath.Join(root, candidate.Name())
		if candidate.Name() == key || !candidate.IsDir() || !ownedRuntime(path) {
			continue
		}
		_, _ = retire(path, entryInUse)
	}
}

// ownedRuntime reports whether path, a directory in the cache root, is a
// runtime entry: one carrying a manifest this program wrote, or a staging
// directory an interrupted run abandoned. DRUPACK_CACHE_DIR can name a
// directory that already holds the reader's own files, which stay. Staging
// runs under the root lock, so no live staging directory exists where this runs.
func ownedRuntime(path string) bool {
	if abandoned, _ := filepath.Match("*"+stagingPrefix+"*", filepath.Base(path)); abandoned {
		return true
	}
	_, err := readManifest(path)
	return err == nil
}

// activeKey returns the key root's active file names. The file lives in the
// user's cache directory, a trust boundary, so its content must name a
// single path element rather than a path Prepare would join outside root.
func activeKey(root string) (string, error) {
	data, err := os.ReadFile(filepath.Join(root, activeName)) //nolint:gosec // G703: the operator sets this path.
	if err != nil {
		return "", err
	}
	key := strings.TrimSpace(string(data))
	if !payload.SingleElement(key) {
		return "", fmt.Errorf("active names an unsafe entry: %q", key)
	}
	return key, nil
}

// stagingFailure names the cache root and the total bytes m's files need, so
// a bare I/O error also says where to look and how much space to free.
func stagingFailure(root string, m payload.Manifest, err error) error {
	var total int64
	for _, file := range m.Files {
		total += file.Size
	}
	return fmt.Errorf("could not unpack the runtime into %s, which needs %d bytes: %w", root, total, err)
}

func verifyChecksums(directory string, m payload.Manifest) error {
	for _, file := range m.Files {
		sum, err := payload.HashFile(filepath.Join(directory, filepath.FromSlash(file.Path)))
		if err != nil {
			return err
		}
		if sum != file.SHA256 {
			return fmt.Errorf("checksum mismatch: %s", file.Path)
		}
	}
	return nil
}

func writeActive(root, key string) error {
	return fsguard.WriteFile(filepath.Join(root, activeName), []byte(key+"\n"))
}

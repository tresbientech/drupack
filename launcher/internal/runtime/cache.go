package runtime

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"unicode/utf8"
)

// rootMode is the permission every candidate cache root is created with.
const rootMode = 0700

// stagingPrefix separates a cache key from the random suffix os.MkdirTemp adds,
// and removeOthers matches on it to clear what an interrupted run left.
const stagingPrefix = ".staging-"

// activeName holds the key of the entry a start last activated, and lockName
// serializes staging. Both live in the cache root, beside the entries.
const activeName = "active"
const lockName = "lock"

// usageName lives inside one entry, runtime or application, and carries the
// lock a running start holds on the files it serves from. Cleanup tests it
// before removing anything.
const usageName = ".inuse"

// Root returns the cache directory a runtime unpacks into, creating it. A
// user-named directory that refuses writes is reported, never silently
// swapped for another; an unnamed one falls back from the user cache
// directory to the temporary directory. On Windows, a chosen root PHP's
// startup cannot read as ASCII falls to the temporary directory too, with one
// line written to notice naming why; a root with no ASCII form anywhere stops
// the start instead.
func Root(notice io.Writer) (string, error) {
	if dir := os.Getenv("DRUPACK_CACHE_DIR"); dir != "" {
		if err := os.MkdirAll(dir, rootMode); err != nil {
			return "", err
		}
		root, err := privateRoot(dir)
		if err != nil {
			return "", err
		}
		return asciiRoot(root, notice)
	}

	var lastErr error
	for _, root := range cacheRoots() {
		if err := os.MkdirAll(root, rootMode); err != nil {
			lastErr = err
			continue
		}
		if owned, err := privateRoot(root); err == nil && owned != "" {
			return asciiRoot(owned, notice)
		} else if err != nil {
			lastErr = err
		}
	}
	return "", lastErr
}

// resolveASCIIRoot returns the first candidate PHP startup can locate: root,
// then fallback. PHP resolves its PHPRC-derived configuration path through the
// ANSI code page, so a root outside that code page breaks extension loading.
// mkdir runs first because Windows allocates a short name only for a path that
// exists. A candidate already in ASCII is returned untouched, since resolve
// also rewrites a long ASCII segment, which no reader asked for. Falling from
// root to fallback writes one line to notice, the shape fallbackOrFail uses
// for its own swap, naming why root was passed over; two exhausted candidates
// stop the start instead, since a site missing its extensions is worse than a
// start that refuses.
func resolveASCIIRoot(root, fallback string, mkdir func(string) error, resolve func(string) (string, error), notice io.Writer) (string, error) {
	ascii, rootErr := asciiForm(root, mkdir, resolve)
	if rootErr == nil {
		return ascii, nil
	}

	ascii, fallbackErr := asciiForm(fallback, mkdir, resolve)
	if fallbackErr == nil {
		fmt.Fprintf(notice, "Could not use cache root %s: %s. Using %s instead.\n", root, rootErr, fallback)
		return ascii, nil
	}

	return "", fmt.Errorf(
		"cache root %s (%s) and fallback %s (%s) have no ASCII path PHP can load extensions from: set DRUPACK_CACHE_DIR to an ASCII directory",
		root, rootErr, fallback, fallbackErr,
	)
}

// asciiForm creates candidate, then returns it unchanged when it is already
// ASCII, or its resolved short name when that is ASCII. An error names why
// candidate carries no ASCII form: mkdir's own failure, resolve's own
// failure, or resolve succeeding on a name that is still not ASCII.
func asciiForm(candidate string, mkdir func(string) error, resolve func(string) (string, error)) (string, error) {
	if err := mkdir(candidate); err != nil {
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

// isASCII reports whether s holds bytes in the ASCII range alone. Any UTF-8
// encoding of a non-ASCII rune uses a byte at or above utf8.RuneSelf, so a
// byte-wise scan is enough.
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

// Prepare returns the directory holding the runtime m describes, staging
// payload into root's cache the first time m's version and payload are seen.
// Activating a newly staged key removes every other version's entry. A start
// that cannot stage the runtime it carries reports the failure and stops,
// since the application beside it belongs to this release alone.
func Prepare(root string, payload []byte, m Manifest, notice io.Writer) (string, error) {
	key := Key(m.Version, payload)

	if entry, ok := warmEntry(root, key, m); ok {
		return entry, nil
	}

	unlock, err := lockRoot(root)
	if err != nil {
		return "", fmt.Errorf("could not lock the runtime cache %s: %w", root, err)
	}
	defer unlock()

	// Another process may have finished staging while this one waited on the lock.
	if entry, ok := warmEntry(root, key, m); ok {
		return entry, nil
	}

	fmt.Fprintf(notice, "Unpacking Drupack %s. This happens once for each version.\n", m.Version)

	name, err := stage(root, key, payload, m, notice)
	if err != nil {
		return "", stagingFailure(root, m, err)
	}
	if err := writeActive(root, name); err != nil {
		return "", err
	}
	removeOthers(root, name)
	return filepath.Join(root, name), nil
}

// warmEntry reports the directory already holding the runtime m describes: the
// one named after key, or the one active names, since a start that found key's
// name taken staged under a name of its own.
func warmEntry(root, key string, m Manifest) (string, bool) {
	if entry := filepath.Join(root, key); warm(entry, m) {
		return entry, true
	}
	name, err := activeKey(root)
	if err != nil || name == key {
		return "", false
	}
	if entry := filepath.Join(root, name); warm(entry, m) {
		return entry, true
	}
	return "", false
}

// warm reports whether entry already holds the runtime m describes: its
// stored manifest matches m, and every declared file has m's recorded size.
func warm(entry string, m Manifest) bool {
	stored, err := readManifest(entry)
	if err != nil {
		return false
	}
	if !reflect.DeepEqual(stored, m) {
		return false
	}
	return sizesMatch(entry, m)
}

// readManifest loads and validates the manifest entry stores.
func readManifest(entry string) (Manifest, error) {
	data, err := os.ReadFile(filepath.Join(entry, ManifestName))
	if err != nil {
		return Manifest{}, err
	}
	return ParseManifest(data)
}

// sizesMatch reports whether every file m declares is present under entry
// with its declared size.
func sizesMatch(entry string, m Manifest) bool {
	for _, file := range m.Files {
		info, err := os.Stat(filepath.Join(entry, filepath.FromSlash(file.Path)))
		if err != nil || info.Size() != file.Size {
			return false
		}
	}
	return true
}

// removeOthers deletes the other versions' entries, once key is active, so the
// cache holds one version. DRUPACK_CACHE_DIR can name a directory that already
// holds the reader's own files, so a directory goes only when it carries a
// manifest this program wrote, or when it is a staging directory an
// interrupted run abandoned. Staging runs under the root lock, so no live one
// exists here. An entry another start still serves from stays, since removing
// it would pull PHP files out from under a running site. A removal failure
// reports nothing, because the next start retries.
func removeOthers(root, key string) {
	entries, err := os.ReadDir(root)
	if err != nil {
		return
	}
	for _, candidate := range entries {
		name := candidate.Name()
		if name == key || !candidate.IsDir() {
			continue
		}
		path := filepath.Join(root, name)
		if abandoned, _ := filepath.Match("*"+stagingPrefix+"*", name); !abandoned {
			if _, err := readManifest(path); err != nil {
				continue
			}
			if entryInUse(path) {
				continue
			}
		}
		os.RemoveAll(path)
	}
}

// activeKey returns the key root's active file names. The file lives in the
// user's cache directory, a trust boundary, so its content must name a
// single path element rather than a path Prepare would join outside root.
func activeKey(root string) (string, error) {
	data, err := os.ReadFile(filepath.Join(root, activeName))
	if err != nil {
		return "", err
	}
	key := strings.TrimSpace(string(data))
	if !SingleElement(key) {
		return "", fmt.Errorf("active names an unsafe entry: %q", key)
	}
	return key, nil
}

// stagingFailure names the cache root and the total bytes m's files need, so
// a bare I/O error also says where to look and how much space to free.
func stagingFailure(root string, m Manifest, err error) error {
	var total int64
	for _, file := range m.Files {
		total += file.Size
	}
	return fmt.Errorf("could not unpack the runtime into %s, which needs %d bytes: %w", root, total, err)
}

// stage extracts payload into a fresh staging directory, verifies it, and
// names it as an entry. It returns that entry's name, which is key unless a
// directory already holds that name. A checksum failure leaves the cache
// untouched, since the naming happens only after every declared file verifies.
func stage(root, key string, payload []byte, m Manifest, notice io.Writer) (string, error) {
	staging, err := os.MkdirTemp(root, key+stagingPrefix)
	if err != nil {
		return "", err
	}
	defer os.RemoveAll(staging)

	if err := Extract(staging, payload, m, notice); err != nil {
		return "", err
	}
	if err := verifyChecksums(staging, m); err != nil {
		return "", err
	}
	contents, err := json.Marshal(m)
	if err != nil {
		return "", err
	}
	if err := os.WriteFile(filepath.Join(staging, ManifestName), contents, 0600); err != nil {
		return "", err
	}

	name := freeName(root, key)
	return name, os.Rename(staging, filepath.Join(root, name))
}

// freeName returns key, or the first key.N beside it that no directory holds.
// A site runs from its entry with files open, and Windows refuses to rename or
// remove a directory while it does, so a re-stage claims its own name rather
// than displacing the one that is there. The caller holds root's lock, so no
// other process takes the name in between.
func freeName(root, key string) string {
	name := key
	for n := 1; ; n++ {
		if _, err := os.Lstat(filepath.Join(root, name)); err != nil {
			return name
		}
		name = fmt.Sprintf("%s.%d", key, n)
	}
}

func verifyChecksums(directory string, m Manifest) error {
	for _, file := range m.Files {
		sum, err := hashFile(filepath.Join(directory, filepath.FromSlash(file.Path)))
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
	pending := filepath.Join(root, fmt.Sprintf("%s.pending.%d", activeName, os.Getpid()))
	if err := os.WriteFile(pending, []byte(key+"\n"), 0600); err != nil {
		return err
	}
	return os.Rename(pending, filepath.Join(root, activeName))
}

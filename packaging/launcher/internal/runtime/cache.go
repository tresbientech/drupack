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
)

// rootMode is the permission every candidate cache root is created with.
const rootMode = 0700

// stagingPrefix separates a cache key from the random suffix os.MkdirTemp adds,
// and removeOthers matches on it to clear what an interrupted run left.
const stagingPrefix = ".staging-"

// Root returns the cache directory a runtime unpacks into, creating it. A
// user-named directory that refuses writes is reported, never silently
// swapped for another; an unnamed one falls back from the user cache
// directory to the temporary directory.
func Root() (string, error) {
	if dir := os.Getenv("DRUPACK_CACHE_DIR"); dir != "" {
		return dir, os.MkdirAll(dir, rootMode)
	}

	var lastErr error
	for _, root := range cacheRoots() {
		if err := os.MkdirAll(root, rootMode); err != nil {
			lastErr = err
			continue
		}
		return root, nil
	}
	return "", lastErr
}

// cacheRoots lists Root's candidates in trial order.
func cacheRoots() []string {
	var roots []string
	if cache, err := os.UserCacheDir(); err == nil {
		roots = append(roots, filepath.Join(cache, "Drupack", "runtime"))
	}
	// The temporary directory is world-writable, so another local user could
	// pre-create a shared path and leave a runtime there for this one to run.
	// Naming it per uid keeps each user in their own directory.
	owned := fmt.Sprintf("Drupack-%d", os.Getuid())
	return append(roots, filepath.Join(os.TempDir(), owned, "runtime"))
}

// Key names the cache entry for a version and its payload, so a payload
// change without a version bump still lands in its own entry.
func Key(version string, payload []byte) string {
	sum := sha256.Sum256(payload)
	return version + "-" + hex.EncodeToString(sum[:])[:12]
}

// Prepare returns the directory holding the runtime m describes, staging
// payload into root's cache the first time m's version and payload are seen.
// Activating a newly staged key removes every other version's entry. A
// staging failure falls back to root's active entry, when that entry is
// still warm for its own manifest.
func Prepare(root string, payload []byte, m Manifest, notice io.Writer) (string, error) {
	key := Key(m.Version, payload)
	entry := filepath.Join(root, key)

	if warm(entry, m) {
		return entry, nil
	}

	unlock, err := lockRoot(root)
	if err != nil {
		return "", err
	}
	defer unlock()

	// Another process may have finished staging while this one waited on the lock.
	if warm(entry, m) {
		return entry, nil
	}

	fmt.Fprintf(notice, "Unpacking Drupack %s. This happens once for each version.\n", m.Version)

	if err := stage(root, entry, key, payload, m); err != nil {
		wrapped := stagingFailure(root, m, err)
		if fallbackEntry, ok := activeFallback(root); ok {
			fmt.Fprintf(notice, "Could not unpack Drupack %s: %s. Using the runtime already in the cache.\n", m.Version, wrapped)
			return fallbackEntry, nil
		}
		return "", wrapped
	}
	if err := writeActive(root, key); err != nil {
		return "", err
	}
	removeOthers(root, key)
	return entry, nil
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
// exists here. A removal failure reports nothing, because the next start
// retries.
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
		}
		os.RemoveAll(path)
	}
}

// activeFallback returns the cache entry root's active file names, when its
// files still hash to what its own manifest declares. That manifest, not the
// one Prepare was asked to stage, is the authority here, since a fallback
// entry's version differs from the embedded one. A warm start compares sizes
// alone, but this path is about to run a runtime the build cannot vouch for,
// so it hashes every file. The cost lands only when staging has failed.
func activeFallback(root string) (string, bool) {
	key, err := activeKey(root)
	if err != nil {
		return "", false
	}
	entry := filepath.Join(root, key)
	stored, err := readManifest(entry)
	if err != nil {
		return "", false
	}
	if err := verifyChecksums(entry, stored); err != nil {
		return "", false
	}
	return entry, true
}

// activeKey returns the key root's active file names. The file lives in the
// user's cache directory, a trust boundary, so its content must name a
// single path element rather than a path Prepare would join outside root.
func activeKey(root string) (string, error) {
	data, err := os.ReadFile(filepath.Join(root, "active"))
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
// swaps it in for entry. A checksum failure leaves entry untouched, since the
// swap happens only after every declared file verifies.
func stage(root, entry, key string, payload []byte, m Manifest) error {
	staging, err := os.MkdirTemp(root, key+stagingPrefix)
	if err != nil {
		return err
	}
	defer os.RemoveAll(staging)

	if err := Extract(staging, payload, m); err != nil {
		return err
	}
	if err := verifyChecksums(staging, m); err != nil {
		return err
	}
	contents, err := json.Marshal(m)
	if err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(staging, ManifestName), contents, 0600); err != nil {
		return err
	}

	if _, err := os.Stat(entry); err == nil {
		invalid := fmt.Sprintf("%s.invalid-%d", entry, os.Getpid())
		if err := os.Rename(entry, invalid); err != nil {
			return err
		}
		if err := os.RemoveAll(invalid); err != nil {
			return err
		}
	}
	return os.Rename(staging, entry)
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
	pending := filepath.Join(root, fmt.Sprintf("active.pending.%d", os.Getpid()))
	if err := os.WriteFile(pending, []byte(key+"\n"), 0600); err != nil {
		return err
	}
	return os.Rename(pending, filepath.Join(root, "active"))
}

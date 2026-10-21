// Package runtime unpacks the runtime a launcher carries, and packs the
// runtime a launcher will carry, into the shapes both directions share.
package runtime

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

type Manifest struct {
	Version string `json:"version"`
	Entry   string `json:"entry"`
	Files   []File `json:"files"`
}

type File struct {
	Path   string `json:"path"`
	Size   int64  `json:"size"`
	SHA256 string `json:"sha256"`
}

const ManifestName = "manifest.json"

// ParseManifest decodes and validates data. The stored copy lives in the
// user's cache directory, which the user can edit, so this is a trust
// boundary and keeps its checks.
func ParseManifest(data []byte) (Manifest, error) {
	var m Manifest
	if err := json.Unmarshal(data, &m); err != nil {
		return Manifest{}, err
	}
	if !SingleElement(m.Version) {
		return Manifest{}, fmt.Errorf("manifest version is not a single path element: %q", m.Version)
	}
	entryDeclared := false
	for _, file := range m.Files {
		if !SafePath(file.Path) {
			return Manifest{}, fmt.Errorf("manifest contains an unsafe path: %s", file.Path)
		}
		if file.SHA256 == "" {
			return Manifest{}, fmt.Errorf("manifest has no checksum for %s", file.Path)
		}
		if file.Path == m.Entry {
			entryDeclared = true
		}
	}
	if !entryDeclared {
		return Manifest{}, fmt.Errorf("manifest entry is not a declared file: %s", m.Entry)
	}
	return m, nil
}

// SafePath reports whether path is safe to join under an extraction root.
// filepath.IsAbs calls a path like C:evil or \\server\share relative, since
// neither starts with a root slash; filepath.VolumeName catches both, and
// returns "" on unix, where the check costs nothing. Windows calls a rooted
// path like \etc\passwd relative too, so the separator prefix is refused on
// its own: a manifest arrives with the payload and names only relative files.
func SafePath(path string) bool {
	clean := filepath.Clean(filepath.FromSlash(path))
	return path != "" && !filepath.IsAbs(clean) && clean != "." &&
		!strings.HasPrefix(clean, ".."+string(filepath.Separator)) && clean != ".." &&
		!strings.HasPrefix(clean, string(filepath.Separator)) &&
		filepath.VolumeName(clean) == ""
}

// SingleElement reports whether value names exactly one path element, so it
// is safe to join under a root without escaping or naming the root itself.
func SingleElement(value string) bool {
	return SafePath(value) && filepath.Base(value) == value
}

// HashFile returns the hex SHA-256 of the file at path. It streams the file,
// since runtime files can run to hundreds of megabytes.
func HashFile(path string) (string, error) {
	source, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer source.Close()
	hash := sha256.New()
	if _, err := io.Copy(hash, source); err != nil {
		return "", err
	}
	return hex.EncodeToString(hash.Sum(nil)), nil
}

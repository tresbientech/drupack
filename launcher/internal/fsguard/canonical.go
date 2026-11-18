package fsguard

import (
	"os"
	"path/filepath"
	"strings"
)

// Within reports whether path is directory or lies below it. It compares the paths
// as written, so a caller that needs a link followed resolves both first.
func Within(path, directory string) bool {
	relative, err := filepath.Rel(directory, path)
	return err == nil && relative != ".." && !strings.HasPrefix(relative, ".."+string(filepath.Separator))
}

// SameOrBelow reports whether the existing path is the existing directory or lies below
// it. It compares file identities, so another letter case on a case-insensitive volume
// and a Windows junction name the folder they lead to.
func SameOrBelow(path, directory string) (bool, error) {
	target, err := os.Stat(directory)
	if err != nil {
		return false, err
	}
	for {
		info, err := os.Stat(path)
		if err != nil {
			return false, err
		}
		if os.SameFile(info, target) {
			return true, nil
		}
		parent := filepath.Dir(path)
		if parent == path {
			return false, nil
		}
		path = parent
	}
}

// Canonical rewrites path in Drupack's canonical form: forward slashes. The
// launcher keeps the native form for everything it joins, walks or opens, and
// converts only the three variables it exports for PHP, Caddy and the
// terminal to read.
func Canonical(path string) string {
	return canonical(path, os.PathSeparator)
}

// canonical takes the separator so a test on any host can drive the Windows
// case. A platform whose separator is already '/' gets the path back untouched,
// because a backslash is a legal character in a file name there.
func canonical(path string, separator rune) string {
	if separator == '/' {
		return path
	}
	return strings.ReplaceAll(path, string(separator), "/")
}

package runtime

import (
	"os"
	"strings"
)

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

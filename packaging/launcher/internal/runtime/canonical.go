package runtime

import "strings"

// Canonical rewrites path in Drupack's canonical form: forward slashes. The
// launcher keeps the native form for everything it joins, walks or opens, and
// converts only the three variables it exports for PHP, Caddy and the
// terminal to read. filepath.ToSlash is a no-op once Separator is already
// '/', which is every build but Windows, so the replacement below is what a
// Windows build needs, and what a test run on any host can exercise.
func Canonical(path string) string {
	return strings.ReplaceAll(path, `\`, "/")
}

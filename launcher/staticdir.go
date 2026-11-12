package main

import (
	"fmt"
	"path"
	"path/filepath"
	"regexp"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/caddyfile"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/fsguard"
)

// defaultStaticDirectory is Tome's default tome_static_directory, relative to the docroot.
const defaultStaticDirectory = "../html"

var absolutePath = regexp.MustCompile(`^([A-Za-z]:)?/`)

// staticDirectory resolves a tome_static_directory the way Tome reads it: a relative value
// starts from the Drupal root, the docroot. The . and .. segments are dropped by name, so a
// root spelled with .. compares with the paths it might hold.
func staticDirectory(docroot, setting string) string {
	setting = fsguard.Canonical(setting)
	if !absolutePath.MatchString(setting) {
		setting = docroot + "/" + setting
	}
	prefix := absolutePath.FindString(setting)
	return prefix + strings.TrimPrefix(path.Clean("/"+setting[len(prefix):]), "/")
}

// checkStaticRoot returns root where it points, or refuses it. The static port answers every
// file under its root as stored, so a root holding one of held would answer settings, code and
// keys. Caddy reads a brace in the root as a placeholder each request fills. fold compares
// paths whatever their case, as Windows does.
func checkStaticRoot(root string, held []string, fold bool) (string, error) {
	root = resolved(root)
	if caddyfile.RefuseBraces("", root) != nil {
		return "", fmt.Errorf("The static directory %s holds a brace, which the static port cannot serve. "+
			"Set tome_static_directory in the project's settings.php to another directory.", root)
	}
	for _, entry := range held {
		entry = resolved(entry)
		if holds(root, entry, fold) {
			return "", fmt.Errorf("The static directory %s holds %s, which the static port would serve as files. "+
				"Set tome_static_directory in the project's settings.php to a directory of its own.", root, entry)
		}
	}
	return root, nil
}

// resolved follows the links of a path that exists. A path that does not exist stays as named.
func resolved(p string) string {
	if real, err := filepath.EvalSymlinks(p); err == nil {
		return fsguard.Canonical(real)
	}
	return p
}

func holds(outer, inner string, fold bool) bool {
	if fold {
		outer, inner = strings.ToLower(outer), strings.ToLower(inner)
	}
	return inner == outer || strings.HasPrefix(inner, strings.TrimRight(outer, "/")+"/")
}

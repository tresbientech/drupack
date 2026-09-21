package runtime

import (
	"path/filepath"
	"strings"
)

// caFileName is the vendored trust bundle's name, packed beside the entry
// executable under the runtime directory.
const caFileName = "cacert.pem"

// Environment builds the child environment for a launched runtime from
// directory, the runtime unpacked into the cache, and parent, the launcher's
// own environment. PHPRC always names directory, since FrankenPHP's static
// binary otherwise resolves php.ini from wherever it happens to run rather
// than the runtime the launcher unpacked. DRUPACK_CA_FILE names the packed
// bundle unless parent already sets it, so a caller behind a TLS-inspection
// proxy can still name their own.
func Environment(directory string, parent []string) []string {
	env := make([]string, 0, len(parent)+2)
	callerSetCAFile := false
	for _, entry := range parent {
		if hasKey(entry, "PHPRC") {
			continue
		}
		if hasKey(entry, "DRUPACK_CA_FILE") {
			callerSetCAFile = true
		}
		env = append(env, entry)
	}
	env = append(env, "PHPRC="+directory)
	if !callerSetCAFile {
		env = append(env, "DRUPACK_CA_FILE="+Canonical(filepath.Join(directory, caFileName)))
	}
	return env
}

// hasKey reports whether entry, one os.Environ() line, names key.
func hasKey(entry, key string) bool {
	return strings.HasPrefix(entry, key+"=")
}

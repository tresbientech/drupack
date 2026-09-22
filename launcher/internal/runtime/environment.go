package runtime

import (
	"path/filepath"
	"strings"
)

// caFileName is the vendored trust bundle's name, packed beside the entry
// executable under the runtime directory.
const caFileName = "cacert.pem"

// loaderVariables are the glibc dynamic loader's own controls. A Linux release
// carries a glibc runtime, which runs under the host's loader, so a caller who
// sets any of these loads code of their choosing into the PHP process. The
// launcher drops them on every platform: a static musl runtime and a Windows
// runtime ignore them, so nothing distinguishes the cases.
var loaderVariables = []string{
	"LD_PRELOAD",
	"LD_LIBRARY_PATH",
	"LD_AUDIT",
	"GLIBC_TUNABLES",
}

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
		if loaderControl(entry) {
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

// loaderControl reports whether entry names one of the dynamic loader's own
// controls, which the launcher does not pass to the runtime it starts.
func loaderControl(entry string) bool {
	for _, name := range loaderVariables {
		if hasKey(entry, name) {
			return true
		}
	}
	return false
}

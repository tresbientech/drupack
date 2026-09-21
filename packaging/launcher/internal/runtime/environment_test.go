package runtime_test

import (
	"testing"

	runtimepkg "git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// lookup returns the value entry names within env, and whether env carries it,
// so a test can assert on one variable without parsing the whole slice.
func lookup(env []string, name string) (string, bool) {
	prefix := name + "="
	for _, entry := range env {
		if len(entry) > len(prefix) && entry[:len(prefix)] == prefix {
			return entry[len(prefix):], true
		}
	}
	return "", false
}

// A caller that already set PHPRC, the way a shell profile or another launcher
// would, must still get the runtime directory: FrankenPHP resolves php.ini
// through PHPRC, so a stale value here would load the wrong settings.
func TestEnvironmentPHPRCAlwaysNamesTheRuntimeDirectory(t *testing.T) {
	parent := []string{"PHPRC=/caller/chosen/path", "HOME=/home/user"}

	env := runtimepkg.Environment("/runtime/dir", parent)

	value, ok := lookup(env, "PHPRC")
	if !ok {
		t.Fatal("PHPRC is missing from the built environment")
	}
	if value != "/runtime/dir" {
		t.Fatalf("PHPRC = %q, want the runtime directory %q", value, "/runtime/dir")
	}
}

// A caller behind a TLS-inspection proxy names their own bundle; the packed
// one must not displace it.
func TestEnvironmentDRUPACKCAFileKeepsACallersValue(t *testing.T) {
	parent := []string{"DRUPACK_CA_FILE=/proxy/bundle.pem", "HOME=/home/user"}

	env := runtimepkg.Environment("/runtime/dir", parent)

	value, ok := lookup(env, "DRUPACK_CA_FILE")
	if !ok {
		t.Fatal("DRUPACK_CA_FILE is missing from the built environment")
	}
	if value != "/proxy/bundle.pem" {
		t.Fatalf("DRUPACK_CA_FILE = %q, want the caller's own bundle %q", value, "/proxy/bundle.pem")
	}
}

// When the caller sets neither variable, Environment points both at the
// runtime directory the launcher unpacked.
func TestEnvironmentSetsBothVariablesWhenTheCallerSetsNeither(t *testing.T) {
	env := runtimepkg.Environment("/runtime/dir", []string{"HOME=/home/user"})

	phprc, ok := lookup(env, "PHPRC")
	if !ok || phprc != "/runtime/dir" {
		t.Fatalf("PHPRC = %q, %v, want %q, true", phprc, ok, "/runtime/dir")
	}
	caFile, ok := lookup(env, "DRUPACK_CA_FILE")
	if !ok || caFile != "/runtime/dir/cacert.pem" {
		t.Fatalf("DRUPACK_CA_FILE = %q, %v, want %q, true", caFile, ok, "/runtime/dir/cacert.pem")
	}
}

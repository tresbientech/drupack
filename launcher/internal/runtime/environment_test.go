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

// count returns how many entries of env name key, so a test can catch a
// duplicate that os/exec would resolve to its last entry, silently
// overriding a caller's own value on Windows.
func count(env []string, key string) int {
	prefix := key + "="
	found := 0
	for _, entry := range env {
		if len(entry) > len(prefix) && entry[:len(prefix)] == prefix {
			found++
		}
	}
	return found
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
	// os/exec resolves a duplicated key to its last entry: a second PHPRC
	// hiding behind the caller's own would silently win on Windows.
	if n := count(env, "PHPRC"); n != 1 {
		t.Fatalf("PHPRC appears %d times in the built environment, want exactly 1", n)
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
	// An implementation that keeps the caller's entry and also appends the
	// bundled one would pass the check above while breaking the override on
	// Windows, where os/exec resolves a duplicate to its last entry.
	if n := count(env, "DRUPACK_CA_FILE"); n != 1 {
		t.Fatalf("DRUPACK_CA_FILE appears %d times in the built environment, want exactly 1", n)
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

// A Linux release carries a glibc runtime, which runs under the host's dynamic
// loader. A caller who sets one of the loader's controls would otherwise load
// code of their choosing into the PHP process.
func TestEnvironmentDropsTheLoaderControls(t *testing.T) {
	parent := []string{
		"LD_PRELOAD=/tmp/evil.so",
		"LD_LIBRARY_PATH=/tmp/lib",
		"LD_AUDIT=/tmp/audit.so",
		"GLIBC_TUNABLES=glibc.malloc.check=0",
		"LD_PRELOADED_BY_NOBODY=keep",
		"HOME=/home/user",
	}
	env := runtimepkg.Environment("/runtime/dir", parent)
	for _, dropped := range []string{"LD_PRELOAD", "LD_LIBRARY_PATH", "LD_AUDIT", "GLIBC_TUNABLES"} {
		if value, found := lookup(env, dropped); found {
			t.Fatalf("Environment kept %s=%s", dropped, value)
		}
	}
	if value, found := lookup(env, "LD_PRELOADED_BY_NOBODY"); !found || value != "keep" {
		t.Fatalf("Environment dropped a variable whose name only starts like a loader control")
	}
	if value, found := lookup(env, "HOME"); !found || value != "/home/user" {
		t.Fatalf("Environment did not keep HOME: %q, %v", value, found)
	}
}

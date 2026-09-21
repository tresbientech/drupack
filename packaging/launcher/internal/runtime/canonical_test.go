package runtime

import "testing"

// The three variables this package's caller exports carry a native Windows
// path, backslash-separated. Canonical rewrites it for PHP, Caddy and the
// terminal, so it agrees with the forward slashes Drupal's own stack traces
// already carry for any path built from an exported value.
func TestCanonical(t *testing.T) {
	cases := []struct {
		variable string
		native   string
		want     string
	}{
		{"DRUPACK_RUNTIME_APP_DIR", `C:\Users\theno\AppData\Local\Drupack\runtime\app\r2e2893a48a83`, "C:/Users/theno/AppData/Local/Drupack/runtime/app/r2e2893a48a83"},
		{"DRUPACK_RUNTIME_BINARY", `C:\Users\theno\AppData\Local\Drupack\drupack.exe`, "C:/Users/theno/AppData/Local/Drupack/drupack.exe"},
		{"DRUPACK_RUNTIME_CWD", `C:\Users\theno\Downloads`, "C:/Users/theno/Downloads"},
	}
	for _, c := range cases {
		t.Run(c.variable, func(t *testing.T) {
			if got := canonical(c.native, '\\'); got != c.want {
				t.Errorf("canonical(%q) = %q, want %q", c.native, got, c.want)
			}
		})
	}
}

// A platform whose separator is already '/' leaves a path alone, because a
// backslash is a legal character in a file name there.
func TestCanonicalKeepsABackslashWhereItIsALegalCharacter(t *testing.T) {
	native := `/home/theodore/a\\b/data`
	if got := canonical(native, '/'); got != native {
		t.Errorf("canonical(%q, '/') = %q, want it unchanged", native, got)
	}
}

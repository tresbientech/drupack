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
			if got := Canonical(c.native); got != c.want {
				t.Errorf("Canonical(%q) = %q, want %q", c.native, got, c.want)
			}
		})
	}
}

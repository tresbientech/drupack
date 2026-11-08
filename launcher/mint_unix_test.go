//go:build unix

package main

import (
	"os"
	"path/filepath"
	"testing"
)

// The stub stands in for the runtime: it answers Drush's user:login with stdout,
// and with stderr on standard error.
func TestMint(t *testing.T) {
	for _, row := range []struct {
		name, stdout, stderr string
		link, failure        string
	}{
		{"the link Drush mints at the URL", "http://localhost:3787/user/reset/1/abc\n", "", "http://localhost:3787/user/reset/1/abc", ""},
		{"the reason Drush gave when it mints no link", "", "Account is\nblocked", "", "Account is blocked"},
		{"a link that does not start with the URL", "http://elsewhere/user/reset/1/abc", "", "", ""},
	} {
		stub := filepath.Join(t.TempDir(), "runtime")
		script := "#!/bin/sh\nprintf '%s' '" + row.stdout + "'\nprintf '%s' '" + row.stderr + "' >&2\n"
		if err := os.WriteFile(stub, []byte(script), 0o700); err != nil {
			t.Fatal(err)
		}
		link, failure, err := mint(Launch{Entry: stub}, nil, "http://localhost:3787", "drush.php", t.TempDir())
		if link != row.link || failure != row.failure || err != nil {
			t.Errorf("%s: mint = %q, %q, %v", row.name, link, failure, err)
		}
	}
}

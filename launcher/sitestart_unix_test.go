//go:build unix

package main

import (
	"os"
	"path/filepath"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
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
		{"a link on a longer port", "http://localhost:37870/user/reset/1/abc", "", "", ""},
		{"a link naming the URL as its user", "http://localhost:3787@elsewhere/user/reset/1/abc", "", "", ""},
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

// The stub answers the check operation, so the site start reaches its brace
// refusals. Each Site data or files path holds a brace typed or behind a link.
func TestSiteStartRefusesBraces(t *testing.T) {
	f := newSite(t, siteconfig.Site{Port: 7225})
	stub := filepath.Join(t.TempDir(), "runtime")
	if err := os.WriteFile(stub, []byte("#!/bin/sh\necho '{\"installed\": false}'\n"), 0o700); err != nil {
		t.Fatal(err)
	}
	l := f.launch()
	l.Entry = stub
	braced := filepath.Join(realDir(t), "{client}")
	if err := os.MkdirAll(braced, 0o700); err != nil {
		t.Fatal(err)
	}
	linked := filepath.Join(f.cwd, "linked")
	if err := os.Symlink(braced, linked); err != nil {
		t.Fatal(err)
	}
	t.Chdir(f.cwd)
	for _, row := range []struct {
		name  string
		words []string
		want  string
	}{
		{"a typed --data-dir", []string{"--data-dir", braced}, "--data-dir must not contain { or }: " + braced},
		{"a typed --files-dir", []string{"--files-dir", braced}, "--files-dir must not contain { or }: " + braced},
		{"a --data-dir link", []string{"--data-dir", "linked"}, "--data-dir must not contain { or }: " + braced},
		{"a --files-dir link", []string{"--files-dir", "linked"}, "--files-dir must not contain { or }: " + braced},
	} {
		c := parseLine(t, false, append([]string{"--foreground", "--host", "localhost"}, row.words...)...)
		if err := startSite(l, c); err == nil || err.Error() != row.want {
			t.Errorf("%s: startSite = %v, want %q", row.name, err, row.want)
		}
	}
}

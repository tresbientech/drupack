package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

func TestLogFile(t *testing.T) {
	root := runtime.Canonical(resolved(t.TempDir()))
	docroot, export := root+"/project/web", root+"/project/html"
	for _, directory := range []string{docroot, export, root + "/logs"} {
		if err := os.MkdirAll(directory, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	t.Chdir(root)
	fallback := root + "/cache/access.log"
	link := root + "/link"
	linked := os.Symlink(docroot, link) == nil
	for _, row := range []struct {
		name      string
		options   map[string]string
		want, err string
		fold      bool
	}{
		{"no option", map[string]string{}, "", "", false},
		{"the bare option", map[string]string{"access-log": ""}, fallback, "", false},
		{"a relative file", map[string]string{"access-log": "logs/a.log"}, root + "/logs/a.log", "", false},
		{"an absolute file", map[string]string{"access-log": root + "/logs/../logs/a.log"}, root + "/logs/a.log", "", false},
		{"a file beside the docroot", map[string]string{"access-log": "project/a.log"}, root + "/project/a.log", "", false},
		{"a brace", map[string]string{"access-log": "logs/{a}.log"}, "", "--access-log must not contain { or }: " + root + "/logs/{a}.log", false},
		{"in the docroot", map[string]string{"access-log": "project/web/a.log"}, "", "--access-log names " + root + "/project/web/a.log inside " + docroot + ",", false},
		{"under the docroot", map[string]string{"access-log": "project/web/sites/a.log"}, "", "inside " + docroot + ",", false},
		{"in the static export", map[string]string{"access-log": "project/html/a.log"}, "", "inside " + export + ",", false},
		{"another casing", map[string]string{"access-log": "project/WEB/a.log"}, root + "/project/WEB/a.log", "", false},
		{"another casing on a folding disk", map[string]string{"access-log": "project/WEB/a.log"}, "", "inside " + docroot + ",", true},
	} {
		got, err := logFile(row.options, "access-log", fallback, []string{docroot, export}, row.fold)
		switch {
		case row.err == "" && (err != nil || got != row.want):
			t.Errorf("%s: %q, %v, want %q", row.name, got, err, row.want)
		case row.err != "" && (err == nil || !strings.Contains(err.Error(), row.err)):
			t.Errorf("%s: error %v, want one holding %q", row.name, err, row.err)
		}
	}
	if linked {
		if _, err := logFile(map[string]string{"access-log": filepath.Join(link, "a.log")}, "access-log", fallback, []string{docroot}, false); err == nil || !strings.Contains(err.Error(), "inside "+docroot+",") {
			t.Errorf("a link into the docroot: %v", err)
		}
	}
}

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
	// A disk that folds case resolves a folder that exists to its own casing, so the casing rows name one that does not.
	absent := root + "/absent/web"
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
		{"another casing", map[string]string{"access-log": "absent/WEB/a.log"}, root + "/absent/WEB/a.log", "", false},
		{"another casing on a folding disk", map[string]string{"access-log": "absent/WEB/a.log"}, "", "inside " + absent + ",", true},
	} {
		got, err := logFile(row.options, "access-log", fallback, []string{docroot, export, absent}, row.fold)
		switch {
		case row.err == "" && (err != nil || got != row.want):
			t.Errorf("%s: %q, %v, want %q", row.name, got, err, row.want)
		case row.err != "" && (err == nil || !strings.Contains(err.Error(), row.err)):
			t.Errorf("%s: error %v, want one holding %q", row.name, err, row.err)
		}
	}
	for _, row := range []struct {
		name                string
		options             map[string]string
		access, errors, err string
		fold                bool
	}{
		{"neither option", map[string]string{}, "", "", "", false},
		{"both bare", map[string]string{"access-log": "", "error-log": ""}, root + "/cache/access.log", root + "/cache/error.log", "", false},
		{"the error log alone", map[string]string{"error-log": "logs/e.log"}, "", root + "/logs/e.log", "", false},
		{"one file for both", map[string]string{"access-log": "logs/a.log", "error-log": root + "/logs/a.log"}, "", "", "--access-log and --error-log name one file: " + root + "/logs/a.log", false},
		{"one file, the default of the other", map[string]string{"access-log": "cache/error.log", "error-log": ""}, "", "", "name one file", false},
		{"two casings", map[string]string{"access-log": "logs/a.log", "error-log": "logs/A.log"}, root + "/logs/a.log", root + "/logs/A.log", "", false},
		{"two casings on a folding disk", map[string]string{"access-log": "logs/a.log", "error-log": "logs/A.log"}, "", "", "name one file", true},
		{"an error log in the docroot", map[string]string{"error-log": "project/web/e.log"}, "", "", "--error-log names " + root + "/project/web/e.log inside " + docroot + ",", false},
	} {
		access, errors, err := logFiles(row.options, root+"/cache", []string{docroot, export}, row.fold)
		switch {
		case row.err == "" && (err != nil || access != row.access || errors != row.errors):
			t.Errorf("%s: %q, %q, %v, want %q, %q", row.name, access, errors, err, row.access, row.errors)
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

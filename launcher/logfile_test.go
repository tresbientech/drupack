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
	some := func(value string) *string { return &value }
	link := root + "/link"
	linked := os.Symlink(docroot, link) == nil
	for _, row := range []struct {
		name      string
		written   *string
		want, err string
		fold      bool
	}{
		{"no option", nil, "", "", false},
		{"the bare option", some(""), fallback, "", false},
		{"a relative file", some("logs/a.log"), root + "/logs/a.log", "", false},
		{"an absolute file", some(root + "/logs/../logs/a.log"), root + "/logs/a.log", "", false},
		{"a file beside the docroot", some("project/a.log"), root + "/project/a.log", "", false},
		{"a brace", some("logs/{a}.log"), "", "--access-log must not contain { or }: " + root + "/logs/{a}.log", false},
		{"in the docroot", some("project/web/a.log"), "", "--access-log names " + root + "/project/web/a.log inside " + docroot + ",", false},
		{"under the docroot", some("project/web/sites/a.log"), "", "inside " + docroot + ",", false},
		{"in the static export", some("project/html/a.log"), "", "inside " + export + ",", false},
		{"another casing", some("absent/WEB/a.log"), root + "/absent/WEB/a.log", "", false},
		{"another casing on a folding disk", some("absent/WEB/a.log"), "", "inside " + absent + ",", true},
	} {
		got, err := logFile(row.written, "access-log", fallback, []string{docroot, export, absent}, row.fold)
		switch {
		case row.err == "" && (err != nil || got != row.want):
			t.Errorf("%s: %q, %v, want %q", row.name, got, err, row.want)
		case row.err != "" && (err == nil || !strings.Contains(err.Error(), row.err)):
			t.Errorf("%s: error %v, want one holding %q", row.name, err, row.err)
		}
	}
	for _, row := range []struct {
		name                string
		options             options
		access, errors, err string
		fold                bool
	}{
		{"neither option", options{}, "", "", "", false},
		{"both bare", options{AccessLog: some(""), ErrorLog: some("")}, root + "/cache/access.log", root + "/cache/error.log", "", false},
		{"the error log alone", options{ErrorLog: some("logs/e.log")}, "", root + "/logs/e.log", "", false},
		{"one file for both", options{AccessLog: some("logs/a.log"), ErrorLog: some(root + "/logs/a.log")}, "", "", "--access-log and --error-log name one file: " + root + "/logs/a.log", false},
		{"one file, the default of the other", options{AccessLog: some("cache/error.log"), ErrorLog: some("")}, "", "", "name one file", false},
		{"two casings", options{AccessLog: some("logs/a.log"), ErrorLog: some("logs/A.log")}, root + "/logs/a.log", root + "/logs/A.log", "", false},
		{"two casings on a folding disk", options{AccessLog: some("logs/a.log"), ErrorLog: some("logs/A.log")}, "", "", "name one file", true},
		{"an error log in the docroot", options{ErrorLog: some("project/web/e.log")}, "", "", "--error-log names " + root + "/project/web/e.log inside " + docroot + ",", false},
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
		if _, err := logFile(some(filepath.Join(link, "a.log")), "access-log", fallback, []string{docroot}, false); err == nil || !strings.Contains(err.Error(), "inside "+docroot+",") {
			t.Errorf("a link into the docroot: %v", err)
		}
	}
}

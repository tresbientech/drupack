package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestStaticDirectory(t *testing.T) {
	for _, row := range []struct{ docroot, setting, want string }{
		{"/p/web", "../html", "/p/html"},
		{"/p/web", "export", "/p/web/export"},
		{"/p/web", "../../../..", "/"},
		{"/p/web", "/srv/export", "/srv/export"},
		{"C:/p/web", "C:/export", "C:/export"},
		{"C:/p/web", "C:/p/./../export", "C:/export"},
	} {
		if got := staticDirectory(row.docroot, row.setting); got != row.want {
			t.Errorf("staticDirectory(%q, %q) = %q, want %q", row.docroot, row.setting, got, row.want)
		}
	}
}

func TestCheckStaticRoot(t *testing.T) {
	held := []string{"/p/web", "/cache/folders/f1"}
	for _, row := range []struct {
		root, err string
		fold      bool
	}{
		{"/p/html", "", false},
		{"/p/web/export", "", false},
		{"/p/web", "holds /p/web,", false},
		{"/p", "holds /p/web,", false},
		{"/", "holds /p/web,", false},
		{"/cache", "holds /cache/folders/f1,", false},
		{"/P", "", false},
		{"/P", "holds /p/web,", true},
		{"/srv/{http.request.host}", "holds a brace", false},
	} {
		got, err := checkStaticRoot(row.root, held, row.fold)
		switch {
		case row.err == "" && (err != nil || got != row.root):
			t.Errorf("checkStaticRoot(%q) = %q, %v, want it to pass", row.root, got, err)
		case row.err != "" && (err == nil || !strings.Contains(err.Error(), row.err)):
			t.Errorf("checkStaticRoot(%q) error %v, want one holding %q", row.root, err, row.err)
		}
	}
}

func TestCheckStaticRootFollowsLinks(t *testing.T) {
	project := t.TempDir()
	web := filepath.Join(project, "web")
	if err := os.Mkdir(web, 0o755); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(project, "html")
	if err := os.Symlink(web, link); err != nil {
		t.Skip("no symlinks here")
	}
	real, _ := filepath.EvalSymlinks(web)
	if _, err := checkStaticRoot(link, []string{web}, false); err == nil || !strings.Contains(err.Error(), "holds "+filepath.ToSlash(real)+",") {
		t.Errorf("a link to the docroot gave %v", err)
	}
}

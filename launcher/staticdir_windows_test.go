//go:build windows

package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// A drive-letter root spelled in another case names the docroot Windows holds.
func TestCheckStaticRootFoldsCaseOnADrive(t *testing.T) {
	project := runtime.Canonical(realDir(t))
	web := project + "/web"
	if err := os.Mkdir(filepath.FromSlash(web), 0o755); err != nil {
		t.Fatal(err)
	}
	if !absolutePath.MatchString(web) {
		t.Fatalf("%q is no drive-letter path", web)
	}
	root := staticDirectory(web, strings.ToUpper(web))
	if _, err := checkStaticRoot(root, []string{web}, true); err == nil || !strings.Contains(err.Error(), "holds ") {
		t.Errorf("checkStaticRoot(%q) = %v, want the docroot refused", root, err)
	}
	if got, err := checkStaticRoot(staticDirectory(web, "../html"), []string{web}, true); err != nil || got != project+"/html" {
		t.Errorf("checkStaticRoot(../html) = %q, %v, want %q", got, err, project+"/html")
	}
}

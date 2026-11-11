//go:build unix

package runtime_test

import (
	"io"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"

	runtimepkg "git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// folder makes base/name with mode and returns base and the folder, resolved.
func folder(t *testing.T, name string, mode os.FileMode) (string, string) {
	t.Helper()
	base, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	dir := filepath.Join(base, name)
	if err := runtimepkg.Create(dir); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(dir, mode); err != nil {
		t.Fatal(err)
	}
	return base, dir
}

func TestExposureNamesAGroupWritableFolder(t *testing.T) {
	_, dir := folder(t, "leaf", 0o775)
	exposed, err := runtimepkg.Exposure(dir, false)
	if err != nil || exposed == nil || exposed.Owns || !strings.HasPrefix(exposed.Account, "group ") {
		t.Fatalf("Exposure(%s) = %+v, %v; want the folder's group", dir, exposed, err)
	}
}

func TestExposureWeighsReadAccessOnlyForReaders(t *testing.T) {
	_, dir := folder(t, "leaf", 0o750)
	if exposed, err := runtimepkg.Exposure(dir, false); exposed != nil || err != nil {
		t.Fatalf("a group-readable folder was refused without readers: %+v, %v", exposed, err)
	}
	if exposed, err := runtimepkg.Exposure(dir, true); exposed == nil || err != nil {
		t.Fatalf("a group-readable folder passed with readers: %v", err)
	}
}

func TestExposureNamesAFolderAnotherAccountOwns(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("only root can give a directory to another account")
	}
	_, dir := folder(t, "leaf", 0o700)
	if err := os.Chown(dir, 65534, 65534); err != nil {
		t.Fatal(err)
	}
	exposed, err := runtimepkg.Exposure(dir, true)
	if err != nil || exposed == nil || !exposed.Owns {
		t.Fatalf("Exposure(%s) = %+v, %v; want the folder's owner", dir, exposed, err)
	}
}

func TestRootRefusesALinkToAnOpenFolder(t *testing.T) {
	base, dir := folder(t, "leaf", 0o777)
	link := filepath.Join(base, "link")
	if err := os.Symlink(dir, link); err != nil {
		t.Fatal(err)
	}
	t.Setenv("DRUPACK_CACHE_DIR", link)
	if _, err := runtimepkg.Root("acme", io.Discard); err == nil || !strings.Contains(err.Error(), dir) {
		t.Fatalf("Root through a link returned %v, want a refusal naming %s", err, dir)
	}
}

// openCandidates makes each candidate's per-account folder ahead of Root and
// returns the two. The user cache one is open to every account, the temporary
// one private.
func openCandidates(t *testing.T) (string, string) {
	t.Helper()
	home, cache := folder(t, filepath.Join(".cache", "acme"), 0o777)
	temporary, owned := folder(t, "acme-"+strconv.Itoa(os.Getuid()), 0o700)
	t.Setenv("DRUPACK_CACHE_DIR", "")
	t.Setenv("XDG_CACHE_HOME", "")
	t.Setenv("HOME", home)
	t.Setenv("TMPDIR", temporary)
	return cache, owned
}

func TestRootSkipsACandidateInAFolderOthersCanWrite(t *testing.T) {
	cache, _ := openCandidates(t)
	var notice strings.Builder
	root, err := runtimepkg.Root("acme", &notice)
	if err != nil {
		t.Fatal(err)
	}
	if strings.HasPrefix(root, cache) {
		t.Fatalf("Root used %s in a folder others can write", root)
	}
	if !strings.Contains(notice.String(), cache) || !strings.Contains(notice.String(), "Using "+root+" instead.") {
		t.Errorf("the notice %q names neither the refused folder nor the root used", notice.String())
	}
}

func TestRootRefusesWhenEveryCandidateIsOpen(t *testing.T) {
	_, owned := openCandidates(t)
	if err := os.Chmod(owned, 0o777); err != nil {
		t.Fatal(err)
	}
	if _, err := runtimepkg.Root("acme", io.Discard); err == nil || !strings.Contains(err.Error(), owned) {
		t.Fatalf("Root returned %v, want the temporary candidate's refusal", err)
	}
}

//go:build unix

package runtime_test

// These cases build their states out of permission bits, which root honours for the
// checks Exposure makes: it reads the bits, it does not try a write.

import (
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	runtimepkg "git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// folders makes base/parent/leaf with the given modes and returns base, parent and
// leaf, resolved.
func folders(t *testing.T, parentMode, leafMode os.FileMode) (string, string, string) {
	t.Helper()
	base, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	parent := filepath.Join(base, "parent")
	leaf := filepath.Join(parent, "leaf")
	if err := runtimepkg.Create(leaf); err != nil {
		t.Fatal(err)
	}
	for dir, mode := range map[string]os.FileMode{parent: parentMode, leaf: leafMode} {
		if err := os.Chmod(dir, mode); err != nil {
			t.Fatal(err)
		}
	}
	t.Cleanup(func() { os.Chmod(parent, 0o700) })
	return base, parent, leaf
}

func TestExposureNamesAParentEveryAccountCanWrite(t *testing.T) {
	_, parent, leaf := folders(t, 0o777, 0o700)
	exposed, err := runtimepkg.Exposure(leaf, true)
	if err != nil || exposed == nil || exposed.Folder != parent || exposed.Leaf || exposed.Account != "every account" {
		t.Fatalf("Exposure(%s) = %+v, %v; want the open parent", leaf, exposed, err)
	}
}

func TestExposureAcceptsAStickyParent(t *testing.T) {
	_, _, leaf := folders(t, os.ModeSticky|0o777, 0o700)
	if exposed, err := runtimepkg.Exposure(leaf, true); exposed != nil || err != nil {
		t.Fatalf("a sticky parent was refused: %+v, %v", exposed, err)
	}
}

func TestExposureNamesAGroupWritableLeaf(t *testing.T) {
	_, _, leaf := folders(t, 0o700, 0o775)
	exposed, err := runtimepkg.Exposure(leaf, false)
	if err != nil || exposed == nil || !exposed.Leaf || !strings.HasPrefix(exposed.Account, "group ") {
		t.Fatalf("Exposure(%s) = %+v, %v; want the leaf's group", leaf, exposed, err)
	}
}

func TestExposureWeighsReadAccessOnlyForReaders(t *testing.T) {
	_, _, leaf := folders(t, 0o700, 0o750)
	if exposed, err := runtimepkg.Exposure(leaf, false); exposed != nil || err != nil {
		t.Fatalf("a group-readable leaf was refused without readers: %+v, %v", exposed, err)
	}
	if exposed, err := runtimepkg.Exposure(leaf, true); exposed == nil || err != nil {
		t.Fatalf("a group-readable leaf passed with readers: %v", err)
	}
}

func TestExposureNamesAParentAnotherAccountOwns(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("only root can give a directory to another account")
	}
	_, parent, leaf := folders(t, 0o755, 0o700)
	if err := os.Chown(parent, 65534, 65534); err != nil {
		t.Fatal(err)
	}
	exposed, err := runtimepkg.Exposure(leaf, true)
	if err != nil || exposed == nil || exposed.Folder != parent || !exposed.Owns {
		t.Fatalf("Exposure(%s) = %+v, %v; want the parent's owner", leaf, exposed, err)
	}
}

func TestRootRefusesACacheDirUnderAnOpenParent(t *testing.T) {
	_, parent, _ := folders(t, 0o777, 0o700)
	t.Setenv("DRUPACK_CACHE_DIR", filepath.Join(parent, "cache"))
	if _, err := runtimepkg.Root("acme", io.Discard); err == nil || !strings.Contains(err.Error(), parent) {
		t.Fatalf("Root under an open parent returned %v, want a refusal naming %s", err, parent)
	}
}

func TestRootRefusesALinkIntoAnOpenFolder(t *testing.T) {
	base, parent, leaf := folders(t, 0o777, 0o700)
	link := filepath.Join(base, "link")
	if err := os.Symlink(leaf, link); err != nil {
		t.Fatal(err)
	}
	t.Setenv("DRUPACK_CACHE_DIR", link)
	if _, err := runtimepkg.Root("acme", io.Discard); err == nil || !strings.Contains(err.Error(), parent) {
		t.Fatalf("Root through a link returned %v, want a refusal naming %s", err, parent)
	}
}

func TestRootSkipsAUserCacheUnderAnOpenHome(t *testing.T) {
	_, home, _ := folders(t, 0o777, 0o700)
	t.Setenv("DRUPACK_CACHE_DIR", "")
	t.Setenv("XDG_CACHE_HOME", "")
	t.Setenv("HOME", home)
	root, err := runtimepkg.Root("acme", io.Discard)
	if err != nil {
		t.Fatal(err)
	}
	if strings.HasPrefix(root, home) {
		t.Fatalf("Root used %s under an open home", root)
	}
}

func TestRootRefusesWhenEveryCandidateIsOpen(t *testing.T) {
	_, home, _ := folders(t, 0o777, 0o700)
	_, temporary, _ := folders(t, 0o777, 0o700)
	t.Setenv("DRUPACK_CACHE_DIR", "")
	t.Setenv("XDG_CACHE_HOME", "")
	t.Setenv("HOME", home)
	t.Setenv("TMPDIR", temporary)
	if _, err := runtimepkg.Root("acme", io.Discard); err == nil || !strings.Contains(err.Error(), temporary) {
		t.Fatalf("Root returned %v, want the temporary candidate's refusal", err)
	}
}

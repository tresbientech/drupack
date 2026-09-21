//go:build windows

package runtime

import (
	"fmt"
	"io"
	"os"
	"os/user"
	"path/filepath"
	"syscall"
)

// privateRoot reports root when it is a directory. Windows carries no mode
// bits for Lstat to read, so this checks no further than that: cacheRoots'
// own candidate sits inside the profile Windows keeps to this account, and
// asciiRoot's temp fallback is namespaced per account below instead of
// relying on that guarantee.
func privateRoot(root string) (string, error) {
	info, err := os.Lstat(root)
	if err != nil {
		return "", err
	}
	if !info.IsDir() {
		return "", fmt.Errorf("cache root is not a directory: %s", root)
	}
	return root, nil
}

// cacheRoots lists Root's candidates in trial order. Unix falls back to the
// world-writable temporary directory, which Windows has no counterpart for:
// its temporary directory lives in the same per-account profile as the cache
// directory, so a second candidate would name the same account's storage.
func cacheRoots() []string {
	cache, err := os.UserCacheDir()
	if err != nil {
		return nil
	}
	return []string{filepath.Join(cache, "Drupack", "runtime")}
}

// asciiRoot resolves root to the ASCII path PHP startup needs: phase 1 found
// PHP resolves its PHPRC-derived configuration path through the ANSI code
// page, so an account name it cannot represent breaks extension loading. The
// temporary directory is the fallback rung, since it sits under the same
// per-account profile and carries the same name; its candidate still runs
// through privateRoot like every other one, since %TEMP% is not the fixed,
// account-private location cacheRoots' own candidate is.
func asciiRoot(root string, notice io.Writer) (string, error) {
	fallback, err := fallbackRoot()
	if err != nil {
		return "", err
	}
	mkdir := func(dir string) error {
		if err := os.MkdirAll(dir, rootMode); err != nil {
			return err
		}
		_, err := privateRoot(dir)
		return err
	}
	return resolveASCIIRoot(root, fallback, mkdir, shortPathName, notice)
}

// fallbackRoot names asciiRoot's temp rung. %TMP% and %TEMP% are policy- and
// reader-writable, unlike cacheRoots' own candidate under the profile, so a
// fixed name here would let another account's start collide with this one's;
// namespacing it by SID, the way unix namespaces its own temp candidate by
// uid, keeps every account in its own directory.
func fallbackRoot() (string, error) {
	current, err := user.Current()
	if err != nil {
		return "", err
	}
	return filepath.Join(os.TempDir(), "Drupack-"+current.Uid, "runtime"), nil
}

// shortPathName wraps GetShortPathNameW, the Windows API that names an
// existing path's 8.3 alias — the one Windows-only call resolveASCIIRoot
// needs, kept behind this thin function so the rung logic itself is
// testable on any host.
func shortPathName(path string) (string, error) {
	long, err := syscall.UTF16PtrFromString(path)
	if err != nil {
		return "", err
	}
	buf := make([]uint16, len(path)+1)
	n, err := syscall.GetShortPathName(long, &buf[0], uint32(len(buf)))
	if err != nil {
		return "", err
	}
	if int(n) > len(buf) {
		// The first buffer was too small; n names the size that fits, including
		// the trailing null GetShortPathName counts into its return value.
		buf = make([]uint16, n)
		if _, err := syscall.GetShortPathName(long, &buf[0], uint32(len(buf))); err != nil {
			return "", err
		}
	}
	return syscall.UTF16ToString(buf), nil
}

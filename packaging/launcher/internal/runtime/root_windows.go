//go:build windows

package runtime

import (
	"fmt"
	"os"
	"path/filepath"
	"syscall"
)

// privateRoot reports root when it is a directory. Windows carries no mode
// bits for Lstat to read, and cacheRoots only ever names a directory inside
// the profile Windows keeps to this account.
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
// page, so an account name it cannot represent breaks extension loading.
// The temporary directory is the fallback rung, since it sits under the
// same per-account profile and carries the same name.
func asciiRoot(root string) (string, error) {
	fallback := filepath.Join(os.TempDir(), "Drupack", "runtime")
	mkdir := func(dir string) error { return os.MkdirAll(dir, rootMode) }
	return resolveASCIIRoot(root, fallback, mkdir, shortPathName)
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

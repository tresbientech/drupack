//go:build windows

package cache

import (
	"io"
	"os"
	"os/user"
	"path/filepath"
	"syscall"
)

// cacheRoots lists Root's candidates in trial order. Unix falls back to the
// world-writable temporary directory, which Windows has no counterpart for:
// its temporary directory lives in the same per-account profile as the cache
// directory, so a second candidate would name the same account's storage.
func cacheRoots(name string) []string {
	cache, err := os.UserCacheDir()
	if err != nil {
		return nil
	}
	return []string{filepath.Join(cache, name, "runtime")}
}

// asciiRoot resolves root to the ASCII path PHP startup needs, since PHP resolves its
// PHPRC-derived configuration path through the ANSI code page. The temporary directory is the
// fallback. Its candidate still runs through claimCandidate, since %TEMP% is not account-private
// like cacheRoots' own candidate.
func asciiRoot(root, name string, notice io.Writer) (string, error) {
	fallback, err := fallbackRoot(name)
	if err != nil {
		return "", err
	}
	return resolveASCIIRoot(root, fallback, claimCandidate, shortPathName, notice)
}

// fallbackRoot names asciiRoot's temp rung. %TMP% and %TEMP% are policy- and reader-writable,
// so the name carries the SID, as unix names its temp candidate by uid, keeping every account
// in its own directory.
func fallbackRoot(name string) (string, error) {
	current, err := user.Current()
	if err != nil {
		return "", err
	}
	return filepath.Join(os.TempDir(), name+"-"+current.Uid, "runtime"), nil
}

// shortPathName wraps GetShortPathNameW, which names an existing path's 8.3 alias, so the
// rung logic is testable on any host.
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

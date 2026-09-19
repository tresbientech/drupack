//go:build windows

package runtime

import (
	"fmt"
	"os"
	"path/filepath"
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

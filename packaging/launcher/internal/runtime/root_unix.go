//go:build unix

package runtime

import (
	"fmt"
	"os"
	"path/filepath"
	"syscall"
)

// privateRoot reports root when it is a directory this user owns and no other
// account can write. A launch runs what the root holds, so an unowned or
// shared one is refused rather than repaired.
func privateRoot(root string) (string, error) {
	info, err := os.Lstat(root)
	if err != nil {
		return "", err
	}
	if !info.IsDir() || info.Mode().Perm()&0077 != 0 {
		return "", fmt.Errorf("cache root is not a private directory: %s", root)
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok || int(stat.Uid) != os.Getuid() {
		return "", fmt.Errorf("cache root is not owned by the current user: %s", root)
	}
	return root, nil
}

// cacheRoots lists Root's candidates in trial order.
func cacheRoots() []string {
	var roots []string
	if cache, err := os.UserCacheDir(); err == nil {
		roots = append(roots, filepath.Join(cache, "Drupack", "runtime"))
	}
	// The temporary directory is world-writable, so another local user could
	// pre-create a shared path and leave a runtime there for this one to run.
	// Naming it per uid keeps each user in their own directory.
	owned := fmt.Sprintf("Drupack-%d", os.Getuid())
	return append(roots, filepath.Join(os.TempDir(), owned, "runtime"))
}

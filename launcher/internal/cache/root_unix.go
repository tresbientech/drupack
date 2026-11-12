//go:build unix

package cache

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
)

// asciiRoot returns root unchanged: the ANSI code page problem is Windows-only.
func asciiRoot(root, name string, notice io.Writer) (string, error) {
	return root, nil
}

func cacheRoots(name string) []string {
	var roots []string
	if cache, err := os.UserCacheDir(); err == nil {
		roots = append(roots, filepath.Join(cache, name, "runtime"))
	}
	// The temporary directory is world-writable, so another local user could
	// pre-create a shared path and leave a runtime there for this one to run.
	// Naming it per uid keeps each user in their own directory.
	owned := fmt.Sprintf("%s-%d", name, os.Getuid())
	return append(roots, filepath.Join(os.TempDir(), owned, "runtime"))
}

package runtime

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
)

// nodeDirName holds every unpacked Node release, one entry per version, and
// no active file: a release stays until clean removes it.
const nodeDirName = "node"

// NodeRoot returns the directory holding unpacked Node releases under root.
func NodeRoot(root string) string {
	return filepath.Join(root, nodeDirName)
}

// PrepareNode returns the directory holding the Node release m describes,
// staging payload under NodeRoot the first time m's version and payload are seen.
func PrepareNode(root string, payload []byte, m Manifest, notice io.Writer) (string, error) {
	if err := os.MkdirAll(NodeRoot(root), rootMode); err != nil {
		return "", err
	}
	return prepare(NodeRoot(root), "Node", payload, m, notice)
}

// CleanNode reports every unpacked Node release under root, with the space it
// holds, and removes it unless dry names a listing alone. It reports nothing
// when no release was ever unpacked, as for a site that carries no Node.
func CleanNode(root string, dry bool, out io.Writer) error {
	nodeRoot := NodeRoot(root)
	entries, err := os.ReadDir(nodeRoot)
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	if err != nil {
		return err
	}
	unlock, err := lockRoot(nodeRoot)
	if err != nil {
		return fmt.Errorf("could not lock the Node cache %s: %w", nodeRoot, err)
	}
	defer unlock()

	var count, held int
	var freed int64
	for _, candidate := range entries {
		if !candidate.IsDir() {
			continue
		}
		path := filepath.Join(nodeRoot, candidate.Name())
		size := directorySize(path)
		if entryInUse(path) {
			fmt.Fprintf(out, "  node/%s  %d MB  in use by a running site, kept\n", candidate.Name(), size/megabyte)
			held++
			continue
		}
		fmt.Fprintf(out, "  node/%s  %d MB\n", candidate.Name(), size/megabyte)
		if !dry {
			if err := removeTree(path); err != nil {
				return err
			}
		}
		count++
		freed += size
	}
	if dry {
		fmt.Fprintf(out, "%d MB in %d unpacked Node %s.\n", freed/megabyte, count, releaseWord(count))
	} else {
		fmt.Fprintf(out, "Removed %d unpacked Node %s, freeing %d MB.\n", count, releaseWord(count), freed/megabyte)
	}
	if held > 0 {
		fmt.Fprintf(out, "Kept %d unpacked Node %s a running site still uses. Stop the site, then clean again.\n",
			held, releaseWord(held))
	}
	return nil
}

func releaseWord(count int) string {
	if count == 1 {
		return "release"
	}
	return "releases"
}

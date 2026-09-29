package runtime

import (
	"io"
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

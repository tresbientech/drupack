package runtime

import (
	"io"
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
func (c Cache) PrepareNode(payload []byte, m Manifest, notice io.Writer) (string, error) {
	sp, err := releaseSpec(NodeRoot(c.root), "Node", payload, m, notice)
	if err != nil {
		return "", err
	}
	return c.nodes().prepare(sp, notice)
}

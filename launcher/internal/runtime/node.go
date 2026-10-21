package runtime

import (
	"io"
	"path/filepath"
)

// nodeDirName holds every unpacked Node release, one entry per version, and
// no active file: a release stays until clean removes it.
const nodeDirName = "node"

func NodeRoot(root string) string {
	return filepath.Join(root, nodeDirName)
}

// PrepareNode stages payload under NodeRoot the first time m is seen and returns its directory.
func (c Cache) PrepareNode(payload []byte, m Manifest, notice io.Writer) (string, error) {
	sp, err := releaseSpec(NodeRoot(c.root), "Node", payload, m, notice)
	if err != nil {
		return "", err
	}
	return c.nodes().prepare(sp, notice)
}

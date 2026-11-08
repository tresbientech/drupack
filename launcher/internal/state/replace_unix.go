//go:build unix

package state

import "os"

// replaceFile renames from over to. A reader that has the old file open keeps it.
func replaceFile(from, to string) error {
	return os.Rename(from, to)
}

package fsguard

import (
	"os"
	"path/filepath"
)

// WriteFile replaces path whole with content, through an owner-only file
// staged beside it.
func WriteFile(path string, content []byte) error {
	staged, err := os.CreateTemp(filepath.Dir(path), filepath.Base(path)+".")
	if err != nil {
		return err
	}
	if _, err := staged.Write(content); err != nil {
		staged.Close()
		os.Remove(staged.Name())
		return err
	}
	return ReplaceFile(staged, path)
}

// ReplaceFile syncs and closes staged, then renames it over path. A reader
// sees the old file or the whole new one, after a power loss too. A failure
// removes staged.
func ReplaceFile(staged *os.File, path string) error {
	err := staged.Sync()
	if closed := staged.Close(); err == nil {
		err = closed
	}
	if err == nil {
		err = replaceFile(staged.Name(), path)
	}
	if err != nil {
		os.Remove(staged.Name())
	}
	return err
}

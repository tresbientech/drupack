package runtime

import (
	"fmt"
	"path/filepath"
)

// Exposed names a folder another account can change, and that account.
type Exposed struct {
	Folder  string
	Account string
	// Owns reports that Account owns Folder; otherwise Account holds access to it
	// that the check refuses.
	Owns bool
	// Leaf reports that Folder is the checked path; otherwise it is a parent the
	// account can replace the checked path through.
	Leaf bool
}

func (e *Exposed) Error() string {
	if e.Owns {
		return fmt.Sprintf("%s is owned by %s", e.Folder, e.Account)
	}
	return fmt.Sprintf("%s is open to %s", e.Folder, e.Account)
}

// Exposure reports the first account other than this user, root or the system
// that can change the resolved absolute directory path: what it holds, or the
// path itself through a parent that can rename it. It returns nil when there is
// none, and an error when it cannot tell, which a caller treats as exposed.
func Exposure(path string, readers bool) (*Exposed, error) {
	if exposed, err := leaf(path, readers); exposed != nil || err != nil {
		return exposed, err
	}
	for child, dir := path, filepath.Dir(path); dir != child; child, dir = dir, filepath.Dir(dir) {
		if exposed, err := ancestor(dir, child); exposed != nil || err != nil {
			return exposed, err
		}
	}
	return nil, nil
}

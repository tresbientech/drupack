package runtime

import "fmt"

// Exposed names a folder another account owns or can write, and that account.
type Exposed struct {
	Folder  string
	Account string
	// Owns reports that Account owns Folder; otherwise Account holds access to it
	// that the check refuses.
	Owns bool
}

func (e *Exposed) Error() string {
	if e.Owns {
		return fmt.Sprintf("%s is owned by %s", e.Folder, e.Account)
	}
	return fmt.Sprintf("%s is open to %s", e.Folder, e.Account)
}

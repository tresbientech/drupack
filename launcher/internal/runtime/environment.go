package runtime

import (
	"os"
	"path/filepath"
)

// TLSDir returns the folder holding the user's local root, one per user
// account so every site and the engine share it. It returns "" when the
// system names no config folder, such as a Unix account without HOME, and
// sites then serve HTTP.
func TLSDir() string {
	config, err := os.UserConfigDir()
	if err != nil {
		return ""
	}
	return filepath.Join(config, "drupack", "tls")
}

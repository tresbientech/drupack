//go:build windows

package state_test

import (
	"path/filepath"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// Only a missing lease means no server. A Site data whose path Windows cannot
// search could hold one.
func TestLeaseOnAPathWindowsCannotStatIsAnError(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "bad<name")
	_, err := state.At(dir).Lease()
	if want := "Cannot open the serving lease: " + filepath.ToSlash(filepath.Join(dir, state.LeaseName)) + ": "; err == nil || !strings.HasPrefix(err.Error(), want) {
		t.Errorf("Lease() = %v, want %q", err, want)
	}
}

//go:build unix

package state_test

import (
	"os"
	"path/filepath"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

func TestLeaseNobodyCanOpenIsAnError(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("root opens any file")
	}
	dir := t.TempDir()
	lease := filepath.Join(dir, state.LeaseName)
	if err := os.WriteFile(lease, nil, 0o400); err != nil {
		t.Fatal(err)
	}
	_, err := state.At(dir).Lease()
	if err == nil || err.Error() != "Cannot open the serving lease: "+lease+": permission denied" {
		t.Errorf("Lease() = %v", err)
	}
}

// Only a missing lease means no server. A Site data nobody may search could hold one.
func TestLeaseNobodyCanStatIsAnError(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("root searches any directory")
	}
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, state.LeaseName), nil, 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(dir, 0); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.Chmod(dir, 0o700) })
	_, err := state.At(dir).Lease()
	if err == nil || err.Error() != "Cannot open the serving lease: "+filepath.Join(dir, state.LeaseName)+": permission denied" {
		t.Errorf("Lease() = %v", err)
	}
}

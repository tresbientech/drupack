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
	if err == nil || err.Error() != "Cannot open the serving lease: "+lease {
		t.Errorf("Lease() = %v", err)
	}
}

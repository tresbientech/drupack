//go:build windows

package main

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

// A junction needs no privilege, so any account that can write a parent can plant one.
func TestVendorRootRefusesAJunctionAtVendor(t *testing.T) {
	elsewhere := realDir(t)
	put(t, filepath.Join(elsewhere, "vendor", "autoload.php"), "<?php")
	project := realDir(t)
	if out, err := exec.Command("cmd", "/c", "mklink", "/J", filepath.Join(project, "vendor"), filepath.Join(elsewhere, "vendor")).CombinedOutput(); err != nil {
		t.Fatalf("mklink /J: %v %s", err, out)
	}
	if _, err := os.Stat(filepath.Join(project, "vendor", "autoload.php")); err != nil {
		t.Fatal(err)
	}
	if _, err := vendorRoot(project); err == nil || !strings.Contains(err.Error(), "is a link") {
		t.Fatalf("vendorRoot() = %v, want a refusal naming the junction", err)
	}
}

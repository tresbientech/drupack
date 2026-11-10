//go:build unix

package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestVendorRootRefusesAProjectAnotherAccountOwns(t *testing.T) {
	if os.Getuid() != 0 {
		t.Skip("only root can give a file to another account")
	}
	for _, owned := range []string{"vendor/autoload.php", "vendor", "."} {
		t.Run(owned, func(t *testing.T) {
			project := realDir(t)
			put(t, filepath.Join(project, "vendor", "autoload.php"), "<?php")
			path := filepath.Join(project, owned)
			if err := os.Chown(path, 65534, 65534); err != nil {
				t.Fatal(err)
			}
			_, err := vendorRoot(filepath.Join(project, "vendor"))
			if err == nil || !strings.Contains(err.Error(), filepath.Clean(path)+" is owned by ") {
				t.Fatalf("vendorRoot() = %v, want a refusal naming %s", err, path)
			}
		})
	}
}

func TestVendorRootAcceptsAProjectInOneOfMyGroups(t *testing.T) {
	if os.Getuid() != 0 {
		t.Skip("only root can give a file to another account")
	}
	project := realDir(t)
	put(t, filepath.Join(project, "vendor", "autoload.php"), "<?php")
	for _, owned := range []string{"vendor/autoload.php", "vendor", "."} {
		if err := os.Chown(filepath.Join(project, owned), 65534, os.Getgid()); err != nil {
			t.Fatal(err)
		}
	}
	if got, err := vendorRoot(project); err != nil || got != project {
		t.Fatalf("vendorRoot() = %q, %v, want %q", got, err, project)
	}
}

func TestVendorRootAcceptsARootOwnedProject(t *testing.T) {
	if os.Getuid() != 0 {
		t.Skip("only root can give a file to root")
	}
	project := realDir(t)
	put(t, filepath.Join(project, "vendor", "autoload.php"), "<?php")
	if err := os.Chown(filepath.Join(project, "vendor", "autoload.php"), 0, 0); err != nil {
		t.Fatal(err)
	}
	if got, err := vendorRoot(project); err != nil || got != project {
		t.Fatalf("vendorRoot() = %q, %v, want %q", got, err, project)
	}
}

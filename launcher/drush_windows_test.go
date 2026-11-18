//go:build windows

package main

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

func junction(t *testing.T, path, target string) {
	t.Helper()
	if err := os.MkdirAll(target, 0o700); err != nil {
		t.Fatal(err)
	}
	if out, err := exec.Command("cmd", "/c", "mklink", "/J", path, target).CombinedOutput(); err != nil {
		t.Fatalf("mklink /J: %v %s", err, out)
	}
}

func TestSiteDirsRefuseAJunctionOverPrivateData(t *testing.T) {
	for name, prepare := range map[string]func(t *testing.T, root, data, files string){
		"a files folder that is a junction to the private folder": func(t *testing.T, _, data, files string) {
			junction(t, files, filepath.Join(data, "private"))
		},
		"a files folder that is a junction below the private folder": func(t *testing.T, _, data, files string) {
			junction(t, files, filepath.Join(data, "private", "public"))
		},
		"a chain of junctions to the private folder": func(t *testing.T, root, data, files string) {
			junction(t, filepath.Join(root, "middle"), filepath.Join(data, "private"))
			junction(t, files, filepath.Join(root, "middle"))
		},
		"a private folder that is a junction into the files folder": func(t *testing.T, root, data, files string) {
			junction(t, filepath.Join(data, "private"), filepath.Join(root, "public", "secret"))
			junction(t, files, filepath.Join(root, "public"))
		},
	} {
		t.Run(name, func(t *testing.T) {
			root := realDir(t)
			data := filepath.Join(root, "data")
			if err := os.Mkdir(data, 0o700); err != nil {
				t.Fatal(err)
			}
			prepare(t, root, data, filepath.Join(data, "files"))
			if _, _, err := siteDirs(data, "", false); err == nil || !strings.Contains(err.Error(), "overlaps private Site data") {
				t.Errorf("error = %v", err)
			}
		})
	}
}

func TestSiteDirsServeThroughAJunction(t *testing.T) {
	root := realDir(t)
	data := filepath.Join(root, "data")
	if err := os.Mkdir(data, 0o700); err != nil {
		t.Fatal(err)
	}
	public := filepath.Join(root, "public")
	junction(t, filepath.Join(data, "files"), public)
	if _, _, err := siteDirs(data, "", false); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(public, "translations")); err != nil {
		t.Error(err)
	}
}

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
	if _, err := vendorRoot(project, "./drupack"); err == nil || !strings.Contains(err.Error(), "is a link") {
		t.Fatalf("vendorRoot() = %v, want a refusal naming the junction", err)
	}
}

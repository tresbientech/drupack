package main

import (
	"crypto/x509"
	"os"
	"path/filepath"
	"testing"
)

func pool(t *testing.T, dir string) *x509.CertPool {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(dir, rootCertificateName))
	if err != nil {
		t.Fatal(err)
	}
	roots := x509.NewCertPool()
	if !roots.AppendCertsFromPEM(data) {
		t.Fatal("root.pem holds no certificate")
	}
	return roots
}

func TestATrustedRootServesHTTPS(t *testing.T) {
	dir := t.TempDir()
	if err := newRoot(dir); err != nil {
		t.Fatal(err)
	}
	choice, err := tlsSource(dir, pool(t, dir))
	if err != nil {
		t.Fatal(err)
	}
	if choice.Source != "drupack" || choice.Root != filepath.Join(dir, rootCertificateName) || choice.RootKey != filepath.Join(dir, rootKeyName) {
		t.Fatalf("choice = %+v, want drupack with its root", choice)
	}
}

func TestAnUntrustedRootServesHTTP(t *testing.T) {
	dir := t.TempDir()
	if err := newRoot(dir); err != nil {
		t.Fatal(err)
	}
	choice, err := tlsSource(dir, x509.NewCertPool())
	if err != nil || choice.Source != "none" {
		t.Fatalf("choice = %+v, %v, want none", choice, err)
	}
}

func TestNoRootServesHTTP(t *testing.T) {
	for _, dir := range []string{"", t.TempDir()} {
		choice, err := tlsSource(dir, nil)
		if err != nil || choice.Source != "none" {
			t.Fatalf("dir %q: choice = %+v, %v, want none", dir, choice, err)
		}
	}
}

func TestARootWithoutItsKeyFails(t *testing.T) {
	dir := t.TempDir()
	if err := newRoot(dir); err != nil {
		t.Fatal(err)
	}
	if err := os.Remove(filepath.Join(dir, rootKeyName)); err != nil {
		t.Fatal(err)
	}
	if _, err := tlsSource(dir, pool(t, dir)); err == nil {
		t.Fatal("a root without its key was accepted")
	}
}

func TestTheRootKeyIsTheOwnersAlone(t *testing.T) {
	dir := t.TempDir()
	if err := newRoot(dir); err != nil {
		t.Fatal(err)
	}
	info, err := os.Stat(filepath.Join(dir, rootKeyName))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("root.key mode = %v, want 0600", info.Mode().Perm())
	}
}

// The name constraint is what keeps a stolen key from signing for another site.
func TestTheRootCannotVouchForAnotherSite(t *testing.T) {
	dir := t.TempDir()
	if err := newRoot(dir); err != nil {
		t.Fatal(err)
	}
	root, key, err := readRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	if !root.PermittedDNSDomainsCritical {
		t.Fatal("the name constraint is not critical")
	}
	roots := pool(t, dir)
	for _, name := range []string{"localhost", "site.localhost"} {
		if !verifies(root, key, roots, name) {
			t.Fatalf("%s did not verify", name)
		}
	}
	if verifies(root, key, roots, "example.com") {
		t.Fatal("a certificate for example.com verified under the root")
	}
}

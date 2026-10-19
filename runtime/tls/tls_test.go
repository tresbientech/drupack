package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"encoding/pem"
	"io"
	"math/big"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// noMkcert hides any mkcert this machine has, so the Drupack root decides.
func noMkcert(t *testing.T) {
	t.Setenv("PATH", t.TempDir())
}

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
	noMkcert(t)
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
	noMkcert(t)
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
	noMkcert(t)
	for _, dir := range []string{"", t.TempDir()} {
		choice, err := tlsSource(dir, nil)
		if err != nil || choice.Source != "none" {
			t.Fatalf("dir %q: choice = %+v, %v, want none", dir, choice, err)
		}
	}
}

func TestARootWithoutItsKeyFails(t *testing.T) {
	noMkcert(t)
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

// These cases return before any trust store is touched.
func TestTrustRefusesAnotherArgument(t *testing.T) {
	if err := trust(io.Discard, t.TempDir(), []string{"--force"}); err == nil {
		t.Fatal("trust accepted --force")
	}
}

func TestTrustNeedsAConfigFolder(t *testing.T) {
	if err := trust(io.Discard, "", nil); err == nil {
		t.Fatal("trust ran without a folder")
	}
}

func TestUninstallWithoutARootChangesNothing(t *testing.T) {
	var out strings.Builder
	dir := t.TempDir()
	if err := trust(&out, dir, []string{"--uninstall"}); err != nil {
		t.Fatal(err)
	}
	if out.String() != "No local root in "+dir+".\n" {
		t.Fatalf("printed %q", out.String())
	}
}

// stubMkcert puts an mkcert on PATH that logs its arguments and writes a certificate
// valid for validFor. It returns the log's path and the CAROOT it reports.
func stubMkcert(t *testing.T, validFor time.Duration, withRoot bool) (string, string) {
	bin, caroot, work := t.TempDir(), t.TempDir(), t.TempDir()
	if withRoot {
		if err := os.WriteFile(filepath.Join(caroot, "rootCA.pem"), []byte("stub"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	template := &x509.Certificate{SerialNumber: big.NewInt(1), NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(validFor)}
	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	certificate := filepath.Join(work, "cert.pem")
	if err := os.WriteFile(certificate, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o644); err != nil {
		t.Fatal(err)
	}
	log := filepath.Join(work, "calls")
	script := "#!/bin/sh\necho \"$@\" >> " + log + "\n" +
		"if [ \"$1\" = -CAROOT ]; then echo " + caroot + "; exit 0; fi\n" +
		"cp " + certificate + " \"$2\" && echo key > \"$4\"\n"
	if err := os.WriteFile(filepath.Join(bin, "mkcert"), []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	// The stub comes first, ahead of any real mkcert, and its cp and echo after it.
	t.Setenv("PATH", bin+string(os.PathListSeparator)+"/usr/bin"+string(os.PathListSeparator)+"/bin")
	return log, caroot
}

func calls(t *testing.T, log string) []string {
	data, err := os.ReadFile(log)
	if err != nil {
		t.Fatal(err)
	}
	return strings.Split(strings.TrimSpace(string(data)), "\n")
}

func TestMkcertComesBeforeATrustedRoot(t *testing.T) {
	dir := t.TempDir()
	if err := newRoot(dir); err != nil {
		t.Fatal(err)
	}
	log, caroot := stubMkcert(t, 800*24*time.Hour, true)
	choice, err := tlsSource(dir, pool(t, dir))
	if err != nil {
		t.Fatal(err)
	}
	want := tlsChoice{
		Source:      "mkcert",
		Root:        filepath.Join(caroot, "rootCA.pem"),
		Certificate: filepath.Join(dir, "mkcert", "localhost.pem"),
		Key:         filepath.Join(dir, "mkcert", "localhost-key.pem"),
	}
	if choice != want {
		t.Fatalf("choice = %+v, want %+v", choice, want)
	}
	got := calls(t, log)
	wantCalls := []string{"-CAROOT", "-cert-file " + want.Certificate + " -key-file " + want.Key + " localhost 127.0.0.1 ::1"}
	if strings.Join(got, "|") != strings.Join(wantCalls, "|") {
		t.Fatalf("mkcert ran %q, want %q", got, wantCalls)
	}
}

func TestACurrentMkcertCertificateIsKept(t *testing.T) {
	dir := t.TempDir()
	log, _ := stubMkcert(t, 800*24*time.Hour, true)
	for range 2 {
		if _, err := tlsSource(dir, nil); err != nil {
			t.Fatal(err)
		}
	}
	if n := len(calls(t, log)); n != 3 {
		t.Fatalf("mkcert ran %d times, want -CAROOT twice and one certificate", n)
	}
}

func TestAnMkcertCertificateNearExpiryIsWrittenAgain(t *testing.T) {
	dir := t.TempDir()
	log, _ := stubMkcert(t, 10*24*time.Hour, true)
	for range 2 {
		if _, err := tlsSource(dir, nil); err != nil {
			t.Fatal(err)
		}
	}
	if n := len(calls(t, log)); n != 4 {
		t.Fatalf("mkcert ran %d times, want a certificate on both starts", n)
	}
}

func TestMkcertWithoutItsRootIsSkipped(t *testing.T) {
	dir := t.TempDir()
	stubMkcert(t, 800*24*time.Hour, false)
	choice, err := tlsSource(dir, x509.NewCertPool())
	if err != nil || choice.Source != "none" {
		t.Fatalf("choice = %+v, %v, want none", choice, err)
	}
}

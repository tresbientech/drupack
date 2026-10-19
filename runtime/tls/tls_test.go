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
	"runtime"
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

func TestEachRootNamesItsOwnAuthority(t *testing.T) {
	noMkcert(t)
	authorities := map[string]bool{}
	for range 2 {
		dir := t.TempDir()
		if err := newRoot(dir); err != nil {
			t.Fatal(err)
		}
		first, err := tlsSource(dir, pool(t, dir))
		if err != nil {
			t.Fatal(err)
		}
		again, err := tlsSource(dir, pool(t, dir))
		if err != nil {
			t.Fatal(err)
		}
		if !strings.HasPrefix(first.Authority, "drupack-") || again.Authority != first.Authority {
			t.Fatalf("authorities %q and %q, want one drupack- name per root", first.Authority, again.Authority)
		}
		authorities[first.Authority] = true
	}
	if len(authorities) != 2 {
		t.Fatalf("two roots share the authority %v", authorities)
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
	if runtime.GOOS == "windows" {
		t.Skip("Windows has no Unix mode bits; the ACL on %AppData% guards the key")
	}
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
	if len(root.ExtKeyUsage) != 1 || root.ExtKeyUsage[0] != x509.ExtKeyUsageServerAuth {
		t.Fatalf("root extended key usage = %v, want server authentication alone", root.ExtKeyUsage)
	}
	roots := pool(t, dir)
	for _, name := range []string{"localhost", "site.localhost"} {
		if ok, err := verifies(root, key, roots, name); err != nil || !ok {
			t.Fatalf("%s did not verify", name)
		}
	}
	if ok, err := verifies(root, key, roots, "example.com"); err != nil || ok {
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

// stubCertificate writes a self-signed certificate for localhost valid for validFor. It
// stands in for both mkcert's root and the certificate mkcert writes under it.
func stubCertificate(t *testing.T, validFor time.Duration) string {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	template := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		DNSNames:     []string{"localhost"},
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().Add(validFor),
	}
	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(t.TempDir(), "cert.pem")
	if err := os.WriteFile(path, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o644); err != nil {
		t.Fatal(err)
	}
	return path
}

type stub struct {
	log, caroot, certificate string
}

// stubMkcert puts an mkcert on PATH that logs its arguments and writes a certificate
// valid for validFor, which is also its root when withRoot is set.
func stubMkcert(t *testing.T, validFor time.Duration, withRoot bool) stub {
	if runtime.GOOS == "windows" {
		t.Skip("the mkcert stub is a shell script")
	}
	bin, caroot := t.TempDir(), t.TempDir()
	certificate := stubCertificate(t, validFor)
	if withRoot {
		copyFile(t, certificate, filepath.Join(caroot, "rootCA.pem"))
	}
	log := filepath.Join(t.TempDir(), "calls")
	script := "#!/bin/sh\necho \"$@\" >> " + log + "\n" +
		"if [ \"$1\" = -CAROOT ]; then echo " + caroot + "; exit 0; fi\n" +
		"cp " + certificate + " \"$2\" && echo key > \"$4\"\n"
	if err := os.WriteFile(filepath.Join(bin, "mkcert"), []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	// The stub comes first, ahead of any real mkcert, and its cp and echo after it.
	t.Setenv("PATH", bin+string(os.PathListSeparator)+"/usr/bin"+string(os.PathListSeparator)+"/bin")
	return stub{log: log, caroot: caroot, certificate: certificate}
}

func copyFile(t *testing.T, from, to string) {
	data, err := os.ReadFile(from)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(to, data, 0o644); err != nil {
		t.Fatal(err)
	}
}

// trusting is a system trust store holding each certificate file.
func trusting(t *testing.T, files ...string) *x509.CertPool {
	roots := x509.NewCertPool()
	for _, file := range files {
		data, err := os.ReadFile(file)
		if err != nil {
			t.Fatal(err)
		}
		roots.AppendCertsFromPEM(data)
	}
	return roots
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
	mkcert := stubMkcert(t, 800*24*time.Hour, true)
	choice, err := tlsSource(dir, trusting(t, filepath.Join(dir, rootCertificateName), mkcert.certificate))
	if err != nil {
		t.Fatal(err)
	}
	want := tlsChoice{
		Source:      "mkcert",
		Root:        filepath.Join(mkcert.caroot, "rootCA.pem"),
		Certificate: filepath.Join(dir, "mkcert", "localhost.pem"),
		Key:         filepath.Join(dir, "mkcert", "localhost-key.pem"),
	}
	if choice != want {
		t.Fatalf("choice = %+v, want %+v", choice, want)
	}
	got := calls(t, mkcert.log)
	wantCalls := []string{"-CAROOT", "-cert-file " + want.Certificate + " -key-file " + want.Key + " localhost 127.0.0.1 ::1"}
	if strings.Join(got, "|") != strings.Join(wantCalls, "|") {
		t.Fatalf("mkcert ran %q, want %q", got, wantCalls)
	}
}

func TestACurrentMkcertCertificateIsKept(t *testing.T) {
	dir := t.TempDir()
	mkcert := stubMkcert(t, 800*24*time.Hour, true)
	for range 2 {
		if _, err := tlsSource(dir, trusting(t, mkcert.certificate)); err != nil {
			t.Fatal(err)
		}
	}
	if n := len(calls(t, mkcert.log)); n != 3 {
		t.Fatalf("mkcert ran %d times, want -CAROOT twice and one certificate", n)
	}
}

func TestAnMkcertCertificateNearExpiryIsWrittenAgain(t *testing.T) {
	dir := t.TempDir()
	mkcert := stubMkcert(t, 10*24*time.Hour, true)
	for range 2 {
		if _, err := tlsSource(dir, trusting(t, mkcert.certificate)); err != nil {
			t.Fatal(err)
		}
	}
	if n := len(calls(t, mkcert.log)); n != 4 {
		t.Fatalf("mkcert ran %d times, want a certificate on both starts", n)
	}
}

// A recreated mkcert root leaves the earlier certificate chaining to nothing.
func TestAnMkcertCertificateFromAnEarlierRootIsWrittenAgain(t *testing.T) {
	dir := t.TempDir()
	mkcert := stubMkcert(t, 800*24*time.Hour, true)
	if _, err := tlsSource(dir, trusting(t, mkcert.certificate)); err != nil {
		t.Fatal(err)
	}
	copyFile(t, stubCertificate(t, 800*24*time.Hour), filepath.Join(mkcert.caroot, "rootCA.pem"))
	if _, err := tlsSource(dir, trusting(t, mkcert.certificate)); err != nil {
		t.Fatal(err)
	}
	if n := len(calls(t, mkcert.log)); n != 4 {
		t.Fatalf("mkcert ran %d times, want a certificate after the root changed", n)
	}
}

// mkcert's root exists before `mkcert -install` trusts it.
func TestAnUntrustedMkcertRootFallsThroughToTheDrupackRoot(t *testing.T) {
	dir := t.TempDir()
	if err := newRoot(dir); err != nil {
		t.Fatal(err)
	}
	stubMkcert(t, 800*24*time.Hour, true)
	choice, err := tlsSource(dir, pool(t, dir))
	if err != nil || choice.Source != "drupack" {
		t.Fatalf("choice = %+v, %v, want drupack", choice, err)
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

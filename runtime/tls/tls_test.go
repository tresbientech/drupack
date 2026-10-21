package main

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"io"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"
)

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
	choice, err := tlsSource(dir, pool(t, dir), "localhost", "x.drupack.site")
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
		first, err := tlsSource(dir, pool(t, dir), "localhost", "x.drupack.site")
		if err != nil {
			t.Fatal(err)
		}
		again, err := tlsSource(dir, pool(t, dir), "localhost", "x.drupack.site")
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
	choice, err := tlsSource(dir, x509.NewCertPool(), "localhost", "x.drupack.site")
	if err != nil || choice.Source != "none" {
		t.Fatalf("choice = %+v, %v, want none", choice, err)
	}
}

func TestNoRootServesHTTP(t *testing.T) {
	noMkcert(t)
	for _, dir := range []string{"", t.TempDir()} {
		choice, err := tlsSource(dir, nil, "localhost", "x.drupack.site")
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
	if _, err := tlsSource(dir, pool(t, dir), "localhost", "x.drupack.site"); err == nil {
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

// The name constraint keeps a stolen key from signing for another site.
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
	for _, name := range []string{"localhost", "site.localhost", "drupacked-demo.drupack.site", "127.0.0.1", "::1"} {
		if ok, err := verifies(root, key, roots, name); err != nil || !ok {
			t.Fatalf("%s did not verify", name)
		}
	}
	for _, name := range []string{"example.com", "drupack.site.example.com", "192.168.1.5"} {
		if ok, err := verifies(root, key, roots, name); err != nil || ok {
			t.Fatalf("a certificate for %s verified under the root", name)
		}
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

// Stands in for both mkcert's root and the certificate mkcert writes under it.
func stubCertificate(t *testing.T, validFor time.Duration, names ...string) string {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	template := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		DNSNames:     names,
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

func stubMkcert(t *testing.T, validFor time.Duration, withRoot bool, names ...string) stub {
	if runtime.GOOS == "windows" {
		t.Skip("the mkcert stub is a shell script")
	}
	bin, caroot := t.TempDir(), t.TempDir()
	certificate := stubCertificate(t, validFor, names...)
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
	mkcert := stubMkcert(t, 800*24*time.Hour, true, "localhost", "*.drupack.site")
	choice, err := tlsSource(dir, trusting(t, filepath.Join(dir, rootCertificateName), mkcert.certificate), "localhost", "x.drupack.site")
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
	wantCalls := []string{"-CAROOT", "-cert-file " + want.Certificate + " -key-file " + want.Key + " localhost 127.0.0.1 ::1 *.drupack.site"}
	if strings.Join(got, "|") != strings.Join(wantCalls, "|") {
		t.Fatalf("mkcert ran %q, want %q", got, wantCalls)
	}
}

func TestACurrentMkcertCertificateIsKept(t *testing.T) {
	dir := t.TempDir()
	mkcert := stubMkcert(t, 800*24*time.Hour, true, "localhost", "*.drupack.site")
	for range 2 {
		if _, err := tlsSource(dir, trusting(t, mkcert.certificate), "localhost", "x.drupack.site"); err != nil {
			t.Fatal(err)
		}
	}
	if n := len(calls(t, mkcert.log)); n != 3 {
		t.Fatalf("mkcert ran %d times, want -CAROOT twice and one certificate", n)
	}
}

func TestAnMkcertCertificateNearExpiryIsWrittenAgain(t *testing.T) {
	dir := t.TempDir()
	mkcert := stubMkcert(t, 10*24*time.Hour, true, "localhost", "*.drupack.site")
	for range 2 {
		if _, err := tlsSource(dir, trusting(t, mkcert.certificate), "localhost", "x.drupack.site"); err != nil {
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
	mkcert := stubMkcert(t, 800*24*time.Hour, true, "localhost", "*.drupack.site")
	if _, err := tlsSource(dir, trusting(t, mkcert.certificate), "localhost", "x.drupack.site"); err != nil {
		t.Fatal(err)
	}
	copyFile(t, stubCertificate(t, 800*24*time.Hour, "localhost"), filepath.Join(mkcert.caroot, "rootCA.pem"))
	if _, err := tlsSource(dir, trusting(t, mkcert.certificate), "localhost", "x.drupack.site"); err != nil {
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
	stubMkcert(t, 800*24*time.Hour, true, "localhost", "*.drupack.site")
	choice, err := tlsSource(dir, pool(t, dir), "localhost", "x.drupack.site")
	if err != nil || choice.Source != "drupack" {
		t.Fatalf("choice = %+v, %v, want drupack", choice, err)
	}
}

func TestMkcertWithoutItsRootIsSkipped(t *testing.T) {
	dir := t.TempDir()
	stubMkcert(t, 800*24*time.Hour, false, "localhost", "*.drupack.site")
	choice, err := tlsSource(dir, x509.NewCertPool(), "localhost", "x.drupack.site")
	if err != nil || choice.Source != "none" {
		t.Fatalf("choice = %+v, %v, want none", choice, err)
	}
}

func TestASiteNameOrFolderNameBecomesOneLabel(t *testing.T) {
	for name, want := range map[string]string{
		"drupacked-demo":               "drupacked-demo",
		"acme.intranet":                "acme-intranet",
		"My Shop_2":                    "my-shop-2",
		"-édition-":                    "dition",
		"___":                          "site",
		strings.Repeat("a", 70):        strings.Repeat("a", 63),
		strings.Repeat("a", 62) + "-b": strings.Repeat("a", 62),
	} {
		if got := siteLabel(name); got != want {
			t.Errorf("siteLabel(%q) = %q, want %q", name, got, want)
		}
	}
}

func TestTheNameIsUsedOnlyWhenItResolvesToTheListener(t *testing.T) {
	for _, c := range []struct {
		answer, bind, host, reason string
	}{
		{"127.0.0.1", "127.0.0.1", "x.drupack.site", ""},
		{"::1,127.0.0.1", "127.0.0.1", "x.drupack.site", ""},
		{"0.0.0.0", "127.0.0.1", "localhost", "answered 0.0.0.0"},
		{"203.0.113.9,0.0.0.0", "127.0.0.1", "localhost", "answered 203.0.113.9, 0.0.0.0"},
		{"none", "127.0.0.1", "localhost", "no answer"},
		{"127.0.0.1", "::1", "localhost", ""},
		{"127.0.0.1", "0.0.0.0", "localhost", ""},
	} {
		host, reason := chooseHost(context.Background(), fixedLookup(c.answer), "x.drupack.site", c.bind, "localhost")
		if host != c.host || reason != c.reason {
			t.Errorf("answer %s on %s: got %q %q, want %q %q", c.answer, c.bind, host, reason, c.host, c.reason)
		}
	}
}

func TestAResolverThatDoesNotAnswerInTimeFallsBack(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Millisecond)
	defer cancel()
	silent := func(ctx context.Context, name string) ([]net.IPAddr, error) {
		<-ctx.Done()
		return nil, ctx.Err()
	}
	host, reason := chooseHost(ctx, silent, "x.drupack.site", "127.0.0.1", "localhost")
	if host != "localhost" || reason != "no answer within 1s" {
		t.Fatalf("got %q %q", host, reason)
	}
}

func source(t *testing.T, args ...string) tlsChoice {
	t.Helper()
	var out bytes.Buffer
	if err := writeTLSSource(&out, "", args); err != nil {
		t.Fatal(err)
	}
	var choice tlsChoice
	if err := json.Unmarshal(out.Bytes(), &choice); err != nil {
		t.Fatal(err)
	}
	return choice
}

func TestTLSSourceNamesThePackedSiteOrFallsBackToLocalhost(t *testing.T) {
	t.Setenv("DRUPACK_DNS_ANSWER", "127.0.0.1")
	if c := source(t, "--bind", "127.0.0.1", "--name", "drupacked-demo"); c.Host != "drupacked-demo.drupack.site" || c.Alias != c.Host || c.Reason != "" {
		t.Errorf("resolved: %+v", c)
	}
	t.Setenv("DRUPACK_DNS_ANSWER", "none")
	if c := source(t, "--bind", "127.0.0.1", "--name", "drupacked-demo"); c.Host != "localhost" || c.Alias != "drupacked-demo.drupack.site" || c.Reason != "no answer" {
		t.Errorf("unresolved: %+v", c)
	}
	if c := source(t, "--bind", "127.0.0.1", "--name", "drupacked-demo", "--host", "example.test"); c.Host != "example.test" || c.Reason != "" {
		t.Errorf("--host: %+v", c)
	}
}

func TestTLSSourceFallsBackToTheEngineBind(t *testing.T) {
	t.Setenv("DRUPACK_DNS_ANSWER", "none")
	if c := source(t, "--bind", "127.0.0.1", "--folder", "My Shop"); c.Host != "127.0.0.1" || c.Alias != "my-shop.drupack.site" {
		t.Errorf("%+v", c)
	}
}

func TestAnMkcertCertificateWithoutTheSiteNameIsWrittenAgain(t *testing.T) {
	dir := t.TempDir()
	mkcert := stubMkcert(t, 800*24*time.Hour, true, "localhost")
	for range 2 {
		if _, err := tlsSource(dir, trusting(t, mkcert.certificate), "localhost", "x.drupack.site"); err != nil {
			t.Fatal(err)
		}
	}
	written := 0
	for _, call := range calls(t, mkcert.log) {
		if strings.HasPrefix(call, "-cert-file") {
			written++
		}
	}
	if written != 2 {
		t.Fatalf("mkcert wrote %d certificates, want 2: %q", written, calls(t, mkcert.log))
	}
}

func TestOnlyALoopbackStartAddressedByAServedNameMayServeHTTPS(t *testing.T) {
	t.Setenv("DRUPACK_DNS_ANSWER", "127.0.0.1")
	for _, c := range []struct {
		args     []string
		eligible bool
	}{
		{[]string{"--bind", "127.0.0.1", "--name", "demo"}, true},
		{[]string{"--bind", "127.0.0.1", "--name", "demo", "--host", "localhost"}, true},
		{[]string{"--bind", "::1", "--name", "demo"}, true},
		{[]string{"--bind", "127.0.0.1", "--folder", "shop", "--host", "127.0.0.1"}, true},
		{[]string{"--bind", "127.0.0.1", "--name", "demo", "--host", "example.test"}, false},
		{[]string{"--bind", "127.0.0.1", "--name", "demo", "--host", "site.localhost"}, false},
		{[]string{"--bind", "0.0.0.0", "--name", "demo"}, false},
		{[]string{"--bind", "192.168.1.5", "--folder", "shop"}, false},
	} {
		if got := source(t, c.args...); got.Eligible != c.eligible {
			t.Errorf("%v: eligible = %v", c.args, got.Eligible)
		}
	}
}

// application/tests/tls-choices.json holds one choice per source as tls-source encodes
// it, and process.php's TlsChoice reads that file in the PHP tests. A renamed or dropped
// field fails here until the file, and then TlsChoice, follow.
func TestChoicesMatchThePHPReferenceFile(t *testing.T) {
	choices := map[string]tlsChoice{
		"none": {Source: "none", Host: "localhost", Alias: "demo.drupack.site", Reason: "no answer", Eligible: true},
		"mkcert": {Source: "mkcert", Root: "/caroot/rootCA.pem", Certificate: "/tls/mkcert/localhost.pem", Key: "/tls/mkcert/localhost-key.pem",
			Host: "demo.drupack.site", Alias: "demo.drupack.site", Eligible: true},
		"drupack": {Source: "drupack", Root: "/tls/root.pem", RootKey: "/tls/root.key", Authority: "drupack-0123456789abcdef",
			Host: "demo.drupack.site", Alias: "demo.drupack.site", Eligible: true},
	}
	got, err := json.MarshalIndent(choices, "", "  ")
	if err != nil {
		t.Fatal(err)
	}
	reference := filepath.Join("..", "..", "application", "tests", "tls-choices.json")
	want, err := os.ReadFile(reference)
	if err != nil {
		t.Fatal(err)
	}
	if string(got)+"\n" != string(want) {
		t.Fatalf("%s differs from what tls-source encodes. Replace it with:\n%s", reference, got)
	}
}

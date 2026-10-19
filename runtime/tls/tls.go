// How a loopback site serves HTTPS. The builds copy this file into FrankenPHP's main
// package next to runtime/entrypoint.go.
package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"math/big"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"github.com/smallstep/truststore"
)

// The local root's files inside DRUPACK_TLS_DIR. tls-drupack-global.caddy names both.
const (
	rootCertificateName = "root.pem"
	rootKeyName         = "root.key"
)

// mkcertNames are the hosts tls-mkcert-site.caddy and tls-drupack-site.caddy name.
var mkcertNames = []string{"localhost", "127.0.0.1", "::1"}

// mkcertRenewal is how close to expiry an mkcert certificate is written again.
const mkcertRenewal = 30 * 24 * time.Hour

// tlsChoice is what a start serves: "mkcert" with the certificate files mkcert wrote,
// "drupack" with the root Caddy signs from, or "none" for plain HTTP. Root is what a
// client verifies against.
type tlsChoice struct {
	Source      string `json:"source"`
	Root        string `json:"root,omitempty"`
	RootKey     string `json:"rootKey,omitempty"`
	Certificate string `json:"certificate,omitempty"`
	Key         string `json:"key,omitempty"`
}

// newRoot writes a root that can vouch only for loopback names, so its key
// signs nothing a browser accepts for another site.
func newRoot(dir string) error {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return err
	}
	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 128))
	if err != nil {
		return err
	}
	now := time.Now()
	template := &x509.Certificate{
		SerialNumber:                serial,
		Subject:                     pkix.Name{Organization: []string{"Drupack"}, CommonName: "Drupack local root"},
		NotBefore:                   now.Add(-time.Hour),
		NotAfter:                    now.AddDate(10, 0, 0),
		KeyUsage:                    x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
		BasicConstraintsValid:       true,
		IsCA:                        true,
		PermittedDNSDomainsCritical: true,
		// A DNS constraint without a leading dot also permits every subdomain.
		PermittedDNSDomains: []string{"localhost"},
		PermittedIPRanges: []*net.IPNet{
			{IP: net.IPv4(127, 0, 0, 0).To4(), Mask: net.CIDRMask(8, 32)},
			{IP: net.IPv6loopback, Mask: net.CIDRMask(128, 128)},
		},
	}
	certificate, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		return err
	}
	der, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	// The key first: a root certificate on disk always has its key beside it.
	if err := os.WriteFile(filepath.Join(dir, rootKeyName), pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: der}), 0o600); err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(dir, rootCertificateName), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: certificate}), 0o644)
}

// tlsSource picks what a loopback start serves from dir. mkcert comes first, once its
// root exists, since `mkcert -install` already made the machine trust it. The Drupack
// root counts once a certificate it signs for localhost verifies against roots, the
// check a browser makes. A nil roots means the system's trust store.
func tlsSource(dir string, roots *x509.CertPool) (tlsChoice, error) {
	none := tlsChoice{Source: "none"}
	if dir == "" {
		return none, nil
	}
	if choice, found, err := mkcertSource(dir); found || err != nil {
		return choice, err
	}
	root := filepath.Join(dir, rootCertificateName)
	if _, err := os.Stat(root); errors.Is(err, fs.ErrNotExist) {
		return none, nil
	}
	certificate, key, err := readRoot(dir)
	if err != nil {
		return none, err
	}
	if verifies(certificate, key, roots, "localhost") {
		return tlsChoice{Source: "drupack", Root: root, RootKey: filepath.Join(dir, rootKeyName)}, nil
	}
	return none, nil
}

func readRoot(dir string) (*x509.Certificate, *ecdsa.PrivateKey, error) {
	certificatePEM, err := os.ReadFile(filepath.Join(dir, rootCertificateName))
	if err != nil {
		return nil, nil, err
	}
	keyPEM, err := os.ReadFile(filepath.Join(dir, rootKeyName))
	if err != nil {
		return nil, nil, fmt.Errorf("%s has no %s beside it: %w", rootCertificateName, rootKeyName, err)
	}
	certificateBlock, _ := pem.Decode(certificatePEM)
	keyBlock, _ := pem.Decode(keyPEM)
	if certificateBlock == nil || keyBlock == nil {
		return nil, nil, fmt.Errorf("%s or %s in %s is not PEM", rootCertificateName, rootKeyName, dir)
	}
	certificate, err := x509.ParseCertificate(certificateBlock.Bytes)
	if err != nil {
		return nil, nil, err
	}
	parsed, err := x509.ParsePKCS8PrivateKey(keyBlock.Bytes)
	if err != nil {
		return nil, nil, err
	}
	key, ok := parsed.(*ecdsa.PrivateKey)
	if !ok {
		return nil, nil, fmt.Errorf("%s in %s is not an ECDSA key", rootKeyName, dir)
	}
	return certificate, key, nil
}

// verifies signs a throwaway certificate for name under root and checks it against roots.
func verifies(root *x509.Certificate, rootKey *ecdsa.PrivateKey, roots *x509.CertPool, name string) bool {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return false
	}
	now := time.Now()
	template := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: name},
		DNSNames:     []string{name},
		NotBefore:    now.Add(-time.Minute),
		NotAfter:     now.Add(time.Hour),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	der, err := x509.CreateCertificate(rand.Reader, template, root, &key.PublicKey, rootKey)
	if err != nil {
		return false
	}
	leaf, err := x509.ParseCertificate(der)
	if err != nil {
		return false
	}
	_, err = leaf.Verify(x509.VerifyOptions{DNSName: name, Roots: roots})
	return err == nil
}

// writeTLSSource prints the choice for launch.php and serve.php to read.
func writeTLSSource(out io.Writer, dir string) error {
	choice, err := tlsSource(dir, nil)
	if err != nil {
		return err
	}
	return json.NewEncoder(out).Encode(choice)
}

// trust installs the local root, creating it on first use, into the system's trust
// store and every NSS database (Firefox), or removes it from both with --uninstall.
// It prints one line per store.
func trust(out io.Writer, dir string, args []string) error {
	uninstall := false
	switch {
	case len(args) == 0:
	case len(args) == 1 && args[0] == "--uninstall":
		uninstall = true
	default:
		return fmt.Errorf("trust takes no argument but --uninstall: %v", args)
	}
	if dir == "" {
		return errors.New("this account has no config folder, so it holds no local root")
	}
	root := filepath.Join(dir, rootCertificateName)
	_, err := os.Stat(root)
	absent := errors.Is(err, fs.ErrNotExist)
	if err != nil && !absent {
		return err
	}
	if uninstall {
		if absent {
			fmt.Fprintf(out, "No local root in %s.\n", dir)
			return nil
		}
		return removeRoot(out, dir, root)
	}
	if absent {
		if err := newRoot(dir); err != nil {
			return err
		}
		fmt.Fprintf(out, "Created the local root in %s.\n", dir)
	}
	certificate, _, err := readRoot(dir)
	if err != nil {
		return err
	}
	if err := truststore.InstallFile(root); err != nil {
		return fmt.Errorf("system trust store: %w", err)
	}
	fmt.Fprintln(out, "System trust store: installed.")
	nss, skipped := nssDatabases()
	if skipped != "" {
		fmt.Fprintf(out, "Firefox (NSS): skipped, %s.\n", skipped)
		return nil
	}
	if !nss.Exists(certificate) {
		if err := nss.Install(root, certificate); err != nil {
			return fmt.Errorf("Firefox (NSS): %w", err)
		}
	}
	fmt.Fprintln(out, "Firefox (NSS): installed.")
	return nil
}

func removeRoot(out io.Writer, dir, root string) error {
	certificate, _, err := readRoot(dir)
	if err != nil {
		return err
	}
	if err := truststore.UninstallFile(root); err != nil {
		return fmt.Errorf("system trust store: %w", err)
	}
	fmt.Fprintln(out, "System trust store: removed.")
	if nss, skipped := nssDatabases(); skipped != "" {
		fmt.Fprintf(out, "Firefox (NSS): skipped, %s.\n", skipped)
	} else {
		if err := nss.Uninstall(root, certificate); err != nil {
			return fmt.Errorf("Firefox (NSS): %w", err)
		}
		fmt.Fprintln(out, "Firefox (NSS): removed.")
	}
	for _, name := range []string{rootCertificateName, rootKeyName} {
		if err := os.Remove(filepath.Join(dir, name)); err != nil {
			return err
		}
	}
	fmt.Fprintf(out, "Removed the local root from %s.\n", dir)
	return nil
}

// nssDatabases returns the NSS databases truststore finds, or why it found none.
func nssDatabases() (*truststore.NSSTrust, string) {
	nss, err := truststore.NewNSSTrust()
	if err != nil {
		return nil, "certutil is not installed"
	}
	if err := nss.PreCheck(); err != nil {
		return nil, "no NSS database found"
	}
	return nss, ""
}

// mkcertSource writes a certificate for mkcertNames with the reader's own mkcert, which
// opens its root key itself. found is false when mkcert or its root is absent.
func mkcertSource(dir string) (choice tlsChoice, found bool, err error) {
	mkcert, err := exec.LookPath("mkcert")
	if err != nil {
		return choice, false, nil
	}
	caroot, err := exec.Command(mkcert, "-CAROOT").Output()
	if err != nil {
		return choice, false, fmt.Errorf("mkcert -CAROOT: %w", err)
	}
	root := filepath.Join(strings.TrimSpace(string(caroot)), "rootCA.pem")
	if _, err := os.Stat(root); errors.Is(err, fs.ErrNotExist) {
		return choice, false, nil
	}
	folder := filepath.Join(dir, "mkcert")
	choice = tlsChoice{
		Source:      "mkcert",
		Root:        root,
		Certificate: filepath.Join(folder, "localhost.pem"),
		Key:         filepath.Join(folder, "localhost-key.pem"),
	}
	if current(choice.Certificate) {
		return choice, true, nil
	}
	if err := os.MkdirAll(folder, 0o700); err != nil {
		return choice, true, err
	}
	command := exec.Command(mkcert, append([]string{"-cert-file", choice.Certificate, "-key-file", choice.Key}, mkcertNames...)...)
	if output, err := command.CombinedOutput(); err != nil {
		return choice, true, fmt.Errorf("mkcert: %w: %s", err, output)
	}
	return choice, true, nil
}

// current reports whether the certificate at path exists and outlives mkcertRenewal.
func current(path string) bool {
	data, err := os.ReadFile(path)
	if err != nil {
		return false
	}
	block, _ := pem.Decode(data)
	if block == nil {
		return false
	}
	certificate, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return false
	}
	return time.Until(certificate.NotAfter) > mkcertRenewal
}

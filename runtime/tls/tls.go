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
	"path/filepath"
	"time"
)

// The local root's files inside DRUPACK_TLS_DIR. tls-drupack-global.caddy names both.
const (
	rootCertificateName = "root.pem"
	rootKeyName         = "root.key"
)

// tlsChoice is what a start serves: "drupack" with the root Caddy signs from and
// a client verifies against, or "none" for plain HTTP.
type tlsChoice struct {
	Source  string `json:"source"`
	Root    string `json:"root,omitempty"`
	RootKey string `json:"rootKey,omitempty"`
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

// tlsSource picks what a loopback start serves from dir. The Drupack root counts
// once a certificate it signs for localhost verifies against roots, the check a
// browser makes. A nil roots means the system's trust store.
func tlsSource(dir string, roots *x509.CertPool) (tlsChoice, error) {
	none := tlsChoice{Source: "none"}
	if dir == "" {
		return none, nil
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

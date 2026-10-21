// How a loopback site serves HTTPS. The builds copy this file into FrankenPHP's main
// package next to runtime/entrypoint.go.
package main

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"flag"
	"fmt"
	"io"
	"io/fs"
	"math/big"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/smallstep/truststore"
)

// tls-drupack-global.caddy names both files.
const (
	rootCertificateName = "root.pem"
	rootKeyName         = "root.key"
)

// The Caddy site files name these beside the site's drupack.site name.
var loopbackNames = []string{"localhost", "127.0.0.1", "::1"}

var mkcertNames = append(slices.Clone(loopbackNames), "*."+siteDomain)

// The zone's wildcard record answers 127.0.0.1 for every label.
const siteDomain = "drupack.site"

const lookupLimit = time.Second

type lookupFunc func(ctx context.Context, name string) ([]net.IPAddr, error)

const mkcertRenewal = 30 * 24 * time.Hour

// Source is "mkcert", "drupack" or "none" (plain HTTP). Root is what a client
// verifies against.
type tlsChoice struct {
	Source      string `json:"source"`
	Root        string `json:"root,omitempty"`
	RootKey     string `json:"rootKey,omitempty"`
	Authority   string `json:"authority,omitempty"`
	Certificate string `json:"certificate,omitempty"`
	Key         string `json:"key,omitempty"`
	// Host is what the start's URL names, Alias the site's drupack.site name, and Reason
	// why Host is not Alias when the start tried the name. Eligible reports whether the
	// bind and Host may serve HTTPS from a local root.
	Host     string `json:"host"`
	Alias    string `json:"alias"`
	Reason   string `json:"reason"`
	Eligible bool   `json:"eligible"`
}

// The root vouches only for loopback names and drupack.site, so its key signs
// nothing a browser accepts for another site.
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
		SerialNumber: serial,
		Subject:      pkix.Name{Organization: []string{"Drupack"}, CommonName: "Drupack local root"},
		NotBefore:    now.Add(-time.Hour),
		NotAfter:     now.AddDate(10, 0, 0),
		KeyUsage:     x509.KeyUsageCertSign | x509.KeyUsageCRLSign,
		// Name constraints bind DNS and IP names only. This limits the root to TLS servers,
		// so it cannot vouch for code signing or mail.
		ExtKeyUsage:                 []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid:       true,
		IsCA:                        true,
		PermittedDNSDomainsCritical: true,
		// A DNS constraint without a leading dot also permits every subdomain.
		PermittedDNSDomains: []string{"localhost", siteDomain},
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

// mkcert comes first, once its root exists, since `mkcert -install` already made
// the machine trust it. The Drupack root counts once a certificate it signs for
// host verifies against roots, the check a browser makes. A nil roots means the
// system's trust store.
func tlsSource(dir string, roots *x509.CertPool, host, alias string) (tlsChoice, error) {
	none := tlsChoice{Source: "none"}
	if dir == "" {
		return none, nil
	}
	if choice, found, err := mkcertSource(dir, roots, host, alias); found || err != nil {
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
	trusted, err := verifies(certificate, key, roots, host)
	if err != nil {
		return none, err
	}
	if trusted {
		return tlsChoice{Source: "drupack", Root: root, RootKey: filepath.Join(dir, rootKeyName), Authority: authority(certificate)}, nil
	}
	return none, nil
}

// authority names Caddy's CA for root. Caddy stores a CA's intermediate under its name
// and reuses it without checking it against the configured root, so a new root needs
// a new name.
func authority(root *x509.Certificate) string {
	sum := sha256.Sum256(root.Raw)
	return "drupack-" + hex.EncodeToString(sum[:8])
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

// Only a failed check means untrusted; a root that cannot sign is an error.
func verifies(root *x509.Certificate, rootKey *ecdsa.PrivateKey, roots *x509.CertPool, name string) (bool, error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return false, err
	}
	now := time.Now()
	template := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: name},
		NotBefore:    now.Add(-time.Minute),
		NotAfter:     now.Add(time.Hour),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	if ip := net.ParseIP(name); ip != nil {
		template.IPAddresses = []net.IP{ip}
	} else {
		template.DNSNames = []string{name}
	}
	der, err := x509.CreateCertificate(rand.Reader, template, root, &key.PublicKey, rootKey)
	if err != nil {
		return false, fmt.Errorf("the local root cannot sign: %w", err)
	}
	leaf, err := x509.ParseCertificate(der)
	if err != nil {
		return false, err
	}
	_, err = leaf.Verify(x509.VerifyOptions{DNSName: name, Roots: roots})
	return err == nil, nil
}

func siteLabel(name string) string {
	label := strings.Map(func(r rune) rune {
		if r >= 'a' && r <= 'z' || r >= '0' && r <= '9' || r == '-' {
			return r
		}
		return '-'
	}, strings.ToLower(name))
	if len(label) > 63 {
		label = label[:63]
	}
	if label = strings.Trim(label, "-"); label == "" {
		return "site"
	}
	return label
}

func chooseHost(ctx context.Context, lookup lookupFunc, alias, bind, fallback string) (string, string) {
	// The record answers 127.0.0.1 alone, so the name never reaches another listener.
	if bind != "127.0.0.1" {
		return fallback, ""
	}
	ctx, cancel := context.WithTimeout(ctx, lookupLimit)
	defer cancel()
	addresses, err := lookup(ctx, alias)
	if ctx.Err() != nil {
		return fallback, fmt.Sprintf("no answer within %v", lookupLimit)
	}
	if err != nil || len(addresses) == 0 {
		return fallback, "no answer"
	}
	answered := make([]string, len(addresses))
	for i, address := range addresses {
		if address.IP.Equal(net.IPv4(127, 0, 0, 1)) {
			return alias, ""
		}
		answered[i] = address.IP.String()
	}
	return fallback, "answered " + strings.Join(answered, ", ")
}

// DRUPACK_DNS_ANSWER sets it, so a test picks the resolver's answer.
func fixedLookup(addresses string) lookupFunc {
	return func(ctx context.Context, name string) ([]net.IPAddr, error) {
		if addresses == "none" {
			return nil, &net.DNSError{Err: "no such host", Name: name, IsNotFound: true}
		}
		var answer []net.IPAddr
		for _, field := range strings.Split(addresses, ",") {
			answer = append(answer, net.IPAddr{IP: net.ParseIP(field)})
		}
		return answer, nil
	}
}

// A packed site passes --name and falls back to localhost; the engine passes
// --folder and falls back to its bind address. Only a loopback bind addressed by a
// name Caddy serves reads the local roots; any other start serves plain HTTP.
func writeTLSSource(out io.Writer, dir string, args []string) error {
	flags := flag.NewFlagSet("tls-source", flag.ContinueOnError)
	bind := flags.String("bind", "", "the address the server listens on")
	name := flags.String("name", "", "the packed site's label: its subdomain, otherwise its name")
	folder := flags.String("folder", "", "the Project folder's label: its subdomain, otherwise its name")
	host := flags.String("host", "", "the host the reader asked for")
	if err := flags.Parse(args); err != nil {
		return err
	}
	label, fallback := *name, "localhost"
	if *name == "" {
		label, fallback = *folder, *bind
	}
	alias := siteLabel(label) + "." + siteDomain
	chosen, reason := *host, ""
	if chosen == "" {
		var lookup lookupFunc = net.DefaultResolver.LookupIPAddr
		if answer := os.Getenv("DRUPACK_DNS_ANSWER"); answer != "" {
			lookup = fixedLookup(answer)
		}
		chosen, reason = chooseHost(context.Background(), lookup, alias, *bind, fallback)
	}
	loopback := *bind == "::1" || strings.HasPrefix(*bind, "127.")
	eligible := loopback && (chosen == alias || slices.Contains(loopbackNames, chosen))
	choice := tlsChoice{Source: "none"}
	if eligible {
		var err error
		if choice, err = tlsSource(dir, nil, chosen, alias); err != nil {
			return err
		}
	}
	choice.Host, choice.Alias, choice.Reason, choice.Eligible = chosen, alias, reason, eligible
	return json.NewEncoder(out).Encode(choice)
}

// trust creates the local root on first use and installs it into the system's
// trust store and every NSS database (Firefox), or removes it with --uninstall.
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

// found is false when mkcert or its root is absent, or when roots do not trust
// that root for host, which holds until `mkcert -install` runs.
func mkcertSource(dir string, roots *x509.CertPool, host, alias string) (choice tlsChoice, found bool, err error) {
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
	if !current(choice, alias) {
		if err := os.MkdirAll(folder, 0o700); err != nil {
			return choice, true, err
		}
		command := exec.Command(mkcert, append([]string{"-cert-file", choice.Certificate, "-key-file", choice.Key}, mkcertNames...)...)
		if output, err := command.CombinedOutput(); err != nil {
			return choice, true, fmt.Errorf("mkcert: %w: %s", err, output)
		}
	}
	certificate, err := readCertificate(choice.Certificate)
	if err != nil {
		return choice, true, fmt.Errorf("mkcert wrote no certificate: %w", err)
	}
	if _, err := certificate.Verify(x509.VerifyOptions{DNSName: host, Roots: roots}); err != nil {
		return choice, false, nil
	}
	return choice, true, nil
}

// A recreated mkcert root makes every earlier certificate stale, and a
// certificate that does not cover alias is stale too.
func current(choice tlsChoice, alias string) bool {
	if _, err := os.Stat(choice.Key); err != nil {
		return false
	}
	certificate, err := readCertificate(choice.Certificate)
	if err != nil {
		return false
	}
	root, err := os.ReadFile(choice.Root)
	if err != nil {
		return false
	}
	pool := x509.NewCertPool()
	if !pool.AppendCertsFromPEM(root) {
		return false
	}
	if _, err := certificate.Verify(x509.VerifyOptions{DNSName: alias, Roots: pool}); err != nil {
		return false
	}
	return time.Until(certificate.NotAfter) > mkcertRenewal
}

func readCertificate(path string) (*x509.Certificate, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	block, _ := pem.Decode(data)
	if block == nil {
		return nil, fmt.Errorf("%s is not PEM", path)
	}
	return x509.ParseCertificate(block.Bytes)
}

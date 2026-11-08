package main

// This file builds beside entrypoint.go in FrankenPHP's own main package, where
// Caddy and its modules are linked. runtime/adapt-test.sh runs it in the builder
// image. It adapts and serves each Caddyfile the launcher's tests render.

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"fmt"
	"io"
	"math/big"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/caddyconfig"
)

// renderedRoot is the directory every path of a rendered file starts with.
const renderedRoot = "/drupack-test"

// served is one rendered file placed in a directory of its own, whose name
// holds a double quote.
type served struct {
	root, file       string
	port, staticPort int
}

func freePort(t *testing.T) int {
	t.Helper()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	return listener.Addr().(*net.TCPAddr).Port
}

func write(t *testing.T, path, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

// certificates writes a CA, and a certificate for the loopback names signed by
// it, into dir.
func certificates(t *testing.T, dir string) {
	t.Helper()
	pemFile := func(name, kind string, der []byte) {
		write(t, filepath.Join(dir, name), string(pem.EncodeToMemory(&pem.Block{Type: kind, Bytes: der})))
	}
	key := func() *ecdsa.PrivateKey {
		key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
		if err != nil {
			t.Fatal(err)
		}
		return key
	}
	authority, root := key(), &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Drupack adapter test root"},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().AddDate(10, 0, 0),
		KeyUsage: x509.KeyUsageCertSign, BasicConstraintsValid: true, IsCA: true,
	}
	rootDER, err := x509.CreateCertificate(rand.Reader, root, root, &authority.PublicKey, authority)
	if err != nil {
		t.Fatal(err)
	}
	authorityDER, _ := x509.MarshalPKCS8PrivateKey(authority)
	pemFile("root.pem", "CERTIFICATE", rootDER)
	pemFile("root.key", "PRIVATE KEY", authorityDER)
	leaf, template := key(), &x509.Certificate{
		SerialNumber: big.NewInt(2), Subject: pkix.Name{CommonName: "localhost"},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(24 * time.Hour),
		DNSNames: []string{"localhost", "shop.drupack.site"}, IPAddresses: []net.IP{net.ParseIP("127.0.0.1"), net.ParseIP("::1")},
		ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	leafDER, err := x509.CreateCertificate(rand.Reader, template, root, &leaf.PublicKey, authority)
	if err != nil {
		t.Fatal(err)
	}
	leafKeyDER, _ := x509.MarshalPKCS8PrivateKey(leaf)
	pemFile("cert.pem", "CERTIFICATE", leafDER)
	pemFile("key.pem", "PRIVATE KEY", leafKeyDER)
}

// place writes the rendered file with its paths and ports swapped for a
// directory whose name holds a double quote, and the files the server reads.
func place(t *testing.T, rendered string, projectRules string) served {
	t.Helper()
	s := served{root: filepath.Join(t.TempDir(), `we"b`), port: freePort(t), staticPort: freePort(t)}
	// The renderer writes a double quote as \" inside a quoted token.
	text := strings.ReplaceAll(rendered, renderedRoot, strings.ReplaceAll(s.root, `"`, `\"`))
	text = strings.ReplaceAll(strings.ReplaceAll(text, "3787", strconv.Itoa(s.port)), "3788", strconv.Itoa(s.staticPort))
	write(t, filepath.Join(s.root, "project", "web", "hello.txt"), "hello from a docroot")
	write(t, filepath.Join(s.root, "project", "web", "index.php"), "<?php echo 'php ran';")
	write(t, filepath.Join(s.root, "project", "html", "index.html"), "exported page")
	write(t, filepath.Join(s.root, "data", "static", "index.html"), "exported page")
	write(t, filepath.Join(s.root, "project", "drupack.caddy"), projectRules)
	write(t, filepath.Join(s.root, "app", "drupack.caddy"), projectRules)
	write(t, filepath.Join(s.root, "data", "files", "stored.txt"), "stored file")
	write(t, filepath.Join(s.root, "state", "keep"), "")
	certificates(t, filepath.Join(s.root, "tls"))
	s.file = filepath.Join(s.root, "state", "Caddyfile")
	write(t, s.file, text)
	return s
}

func adapt(file string) ([]byte, error) {
	body, err := os.ReadFile(file)
	if err != nil {
		return nil, err
	}
	adapted, _, err := caddyconfig.GetAdapter("caddyfile").Adapt(body, map[string]any{"filename": file})
	return adapted, err
}

func get(t *testing.T, scheme string, port int, path string) (int, string) {
	t.Helper()
	client := &http.Client{
		Timeout:   20 * time.Second,
		Transport: &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true, ServerName: "localhost"}},
	}
	// Caddy issues a certificate after it loads the configuration, so the first
	// handshake can fail.
	var response *http.Response
	var err error
	for attempt := 0; attempt < 40; attempt++ {
		if response, err = client.Get(fmt.Sprintf("%s://127.0.0.1:%d%s", scheme, port, path)); err == nil {
			break
		}
		time.Sleep(250 * time.Millisecond)
	}
	if err != nil {
		t.Fatalf("GET %s: %v", path, err)
	}
	defer response.Body.Close()
	body, _ := io.ReadAll(response.Body)
	return response.StatusCode, string(body)
}

func TestAdaptRenderedFiles(t *testing.T) {
	goldens := os.Getenv("DRUPACK_ADAPT_FILES")
	if goldens == "" {
		t.Skip("DRUPACK_ADAPT_FILES names no directory of rendered files")
	}
	files, err := filepath.Glob(filepath.Join(goldens, "*.caddyfile"))
	if err != nil || len(files) == 0 {
		t.Fatalf("no rendered files in %s: %v", goldens, err)
	}
	for _, file := range files {
		name := strings.TrimSuffix(filepath.Base(file), ".caddyfile")
		t.Run(name, func(t *testing.T) {
			rendered, err := os.ReadFile(file)
			if err != nil {
				t.Fatal(err)
			}
			t.Setenv("XDG_DATA_HOME", t.TempDir())
			t.Setenv("XDG_CONFIG_HOME", t.TempDir())
			s := place(t, string(rendered), "header X-Project-Rule applied\n")
			adapted, err := adapt(s.file)
			if err != nil {
				t.Fatal(err)
			}
			if err := caddy.Load(adapted, true); err != nil {
				t.Fatal(err)
			}
			defer caddy.Stop()
			scheme := "http"
			if strings.Contains(name, "mkcert") || strings.Contains(name, "drupack") || strings.Contains(name, "everything") {
				scheme = "https"
			}
			// engine-everything and site-everything serve the drupack source.
			id := "f0123456789abcdef"
			if strings.HasPrefix(name, "site-") {
				id = "site-id"
			}
			if status, _ := get(t, scheme, s.port, "/.drupack-id?id="+id); status != 204 {
				t.Errorf("the readiness probe answered %d", status)
			}
			if status, _ := get(t, scheme, s.port, "/.drupack-id?id=other"); status != 404 {
				t.Errorf("a probe with another token answered %d", status)
			}
			if status, body := get(t, scheme, s.port, "/hello.txt"); status != 200 || body != "hello from a docroot" {
				t.Errorf("a file in the docroot: %d %q", status, body)
			}
			if status, _ := get(t, scheme, s.port, "/composer.json"); status != 403 {
				t.Errorf("a guarded file answered %d", status)
			}
			if status, body := get(t, scheme, s.port, "/index.php"); status != 200 || body != "php ran" {
				t.Errorf("PHP: %d %q", status, body)
			}
			if strings.Contains(name, "project-rules") || strings.Contains(name, "everything") {
				// A project's rules run after the guards, on every answer.
				response, err := http.Get(fmt.Sprintf("%s://127.0.0.1:%d/hello.txt", scheme, s.port))
				if err == nil {
					response.Body.Close()
				}
			}
			if strings.HasPrefix(name, "site-") {
				if status, body := get(t, scheme, s.port, "/sites/default/files/stored.txt"); status != 200 || body != "stored file" {
					t.Errorf("a public file: %d %q", status, body)
				}
			}
			if strings.Contains(name, "static") || strings.Contains(name, "everything") {
				if status, body := get(t, scheme, s.staticPort, "/"); status != 200 || body != "exported page" {
					t.Errorf("the static port: %d %q", status, body)
				}
			}
			if strings.Contains(name, "mercure") || strings.Contains(name, "everything") {
				if status, _ := get(t, scheme, s.port, "/.well-known/mercure"); status == 404 {
					t.Error("the Mercure hub answered 404")
				}
			}
		})
	}
}

// A broken drupack.caddy fails the adapter, before any server starts. The error
// names the rendered file, so the runtime adds the project's path to it.
func TestAdaptRefusesBrokenProjectRules(t *testing.T) {
	goldens := os.Getenv("DRUPACK_ADAPT_FILES")
	if goldens == "" {
		t.Skip("DRUPACK_ADAPT_FILES names no directory of rendered files")
	}
	rendered, err := os.ReadFile(filepath.Join(goldens, "engine-project-rules.caddyfile"))
	if err != nil {
		t.Fatal(err)
	}
	s := place(t, string(rendered), "not_a_directive foo\n")
	if _, err = adapt(s.file); err == nil || !strings.Contains(err.Error(), "unrecognized directive: not_a_directive") {
		t.Errorf("adapt error = %v", err)
	}
}

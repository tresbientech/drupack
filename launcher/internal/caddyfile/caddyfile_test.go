package caddyfile

import (
	"flag"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/localtls"
)

var update = flag.Bool("update", false, "rewrite the files in testdata")

// Root is the directory every path of a golden file starts with. The adapter
// test in runtime/frankenphp/adapt_test.go swaps it for a directory it makes.
const Root = "/drupack-test"

// Rows are the rendered files the adapter test reads. Together they render
// every fragment: both modes, each TLS source, static with and without dynamic
// paths, Mercure and a project's rules.
func rows() map[string]Config {
	alias := "shop.drupack.site"
	engine := Config{Mode: Engine, ID: "f0123456789abcdef", Bind: "127.0.0.1", Port: 3787, Docroot: Root + "/project/web", TLS: localtls.Choice{Source: "none", Alias: alias}}
	mkcert := engine
	mkcert.TLS = localtls.Choice{Source: "mkcert", Alias: alias, Root: Root + "/tls/root.pem", Certificate: Root + "/tls/cert.pem", Key: Root + "/tls/key.pem"}
	mkcert.Static = &Static{Bind: "127.0.0.1", Port: 3788, Root: Root + "/project/html"}
	mkcert.LogPath = Root + "/state/caddy.log"
	everything := engine
	everything.Mode, everything.ID = Site, "site-id"
	everything.FilesDir, everything.LogPath = Root+"/data/files", Root+"/data/logs/server.log"
	everything.TLS = localtls.Choice{Source: "drupack", Alias: alias, Root: Root + "/tls/root.pem", RootKey: Root + "/tls/root.key", Authority: "Drupack local root"}
	everything.Mercure = &Mercure{URL: "http://127.0.0.1:3787/.well-known/mercure", Publisher: "p0", Subscriber: "s0", DB: Root + "/state/mercure.db", ProjectRules: Root + "/app/drupack.mercure.caddy"}
	everything.ProjectRules = Root + "/app/drupack.caddy"
	everything.Static = &Static{Bind: "127.0.0.1", Port: 3788, Root: Root + "/data/static", Dynamic: []string{"/api/*"}}
	everything.AccessLog, everything.ErrorLog = Root+"/data/logs/access.log", Root+"/data/logs/error.log"
	everything.TrustedProxies = []string{"192.168.0.58/32", "fd00::/8"}
	return map[string]Config{"engine-none": engine, "engine-static-mkcert": mkcert, "site-everything": everything}
}

func TestRender(t *testing.T) {
	for name, config := range rows() {
		t.Run(name, func(t *testing.T) {
			got, err := Render(config)
			if err != nil {
				t.Fatal(err)
			}
			if strings.Contains(string(got), "{$") {
				t.Error("the file holds an environment reference")
			}
			golden := filepath.Join("testdata", name+".caddyfile")
			if *update {
				if err := os.WriteFile(golden, got, 0o644); err != nil {
					t.Fatal(err)
				}
			}
			want, err := os.ReadFile(golden)
			if err != nil {
				t.Fatal(err)
			}
			if string(got) != string(want) {
				t.Errorf("rendered file differs from %s. Run go test -update to review the change.", golden)
			}
		})
	}
}

func TestQuote(t *testing.T) {
	for value, want := range map[string]string{
		`/plain/path`:   `"/plain/path"`,
		`/we"b/docroot`: `"/we\"b/docroot"`,
		`two "quotes"`:  `"two \"quotes\""`,
		``:              `""`,
		`/a\b`:          `"/a\b"`,
		// The lexer reads \" inside double quotes as a quote, so these go between backticks.
		`/srv/site\`: "`/srv/site\\`",
		`/a\"b`:      "`/a\\\"b`",
	} {
		if got, err := quote(value); got != want || err != nil {
			t.Errorf("quote(%q) = %s, %v, want %s", value, got, err, want)
		}
	}
	value := "/a`b\\"
	if _, err := quote(value); err == nil || err.Error() != "A Caddyfile token cannot hold a backtick together with a backslash before a quote or at the end: "+value {
		t.Errorf("quote(%q) = %v", value, err)
	}
}

func TestRefuseBraces(t *testing.T) {
	for _, value := range []string{"/a{b", "/a}b", "/{$HOME}/x", "{"} {
		want := "docroot must not contain { or }: " + value
		if err := RefuseBraces("docroot", value); err == nil || err.Error() != want {
			t.Errorf("RefuseBraces(%q) = %v", value, err)
		}
	}
	for _, value := range []string{"/plain", `/we"b`, "/a b/c"} {
		if err := RefuseBraces("docroot", value); err != nil {
			t.Errorf("RefuseBraces(%q) = %v", value, err)
		}
	}
}

func TestAddresses(t *testing.T) {
	plain := localtls.Choice{Source: "none", Alias: "a.drupack.site", Host: "a.drupack.site"}
	for name, row := range map[string]struct {
		choice localtls.Choice
		bind   string
		want   string
	}{
		"loopback http": {plain, "127.0.0.1",
			`"http://a.drupack.site:3787", "http://localhost:3787", "http://127.0.0.1:3787", "http://[::1]:3787"`},
		"another loopback address": {plain, "127.0.0.2",
			`"http://a.drupack.site:3787", "http://localhost:3787", "http://127.0.0.1:3787", "http://[::1]:3787", "http://127.0.0.2:3787"`},
		"a host the reader named": {localtls.Choice{Source: "none", Alias: "a.drupack.site", Host: "shop.test"}, "::1",
			`"http://a.drupack.site:3787", "http://localhost:3787", "http://127.0.0.1:3787", "http://[::1]:3787", "http://shop.test:3787"`},
		"no alias": {localtls.Choice{Source: "none", Host: "localhost"}, "127.0.0.1",
			`"http://localhost:3787", "http://127.0.0.1:3787", "http://[::1]:3787"`},
		"network http":   {plain, "0.0.0.0", `"http://:3787"`},
		"host name bind": {plain, "127.example.com", `"http://:3787"`},
		"mkcert": {localtls.Choice{Source: "mkcert", Alias: "a.drupack.site", Host: "shop.test"}, "127.0.0.1",
			`"https://a.drupack.site:3787", "https://localhost:3787", "https://127.0.0.1:3787", "https://[::1]:3787"`},
	} {
		got, err := Config{TLS: row.choice}.Addresses(row.bind, 3787)
		if got != row.want || err != nil {
			t.Errorf("%s: %s %v", name, got, err)
		}
	}
}

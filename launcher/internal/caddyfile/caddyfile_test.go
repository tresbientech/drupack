package caddyfile

import (
	"flag"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/tls"
)

var update = flag.Bool("update", false, "rewrite the files in testdata")

// Root is the directory every path of a golden file starts with. The adapter
// test in runtime/adapt_test.go swaps it for a directory it makes.
const Root = "/drupack-test"

// Rows are the rendered files the adapter test reads, one per row of the D19
// table and each TLS source, static setting and Mercure flag.
func rows() map[string]Config {
	alias := "shop.drupack.site"
	none := tls.Choice{Source: "none", Alias: alias}
	mkcert := tls.Choice{Source: "mkcert", Alias: alias, Root: Root + "/tls/root.pem", Certificate: Root + "/tls/cert.pem", Key: Root + "/tls/key.pem"}
	drupack := tls.Choice{Source: "drupack", Alias: alias, Root: Root + "/tls/root.pem", RootKey: Root + "/tls/root.key", Authority: "Drupack local root"}
	engine := Config{Mode: Engine, ID: "f0123456789abcdef", Bind: "127.0.0.1", Port: 3787, Docroot: Root + "/project/web", TLS: none}
	with := func(change func(*Config)) Config {
		c := engine
		change(&c)
		return c
	}
	hub := &Mercure{URL: "http://127.0.0.1:3787/.well-known/mercure", Publisher: "p0", Subscriber: "s0", DB: Root + "/state/mercure.db"}
	site := with(func(c *Config) {
		c.Mode, c.ID = Site, "site-id"
		c.FilesDir, c.LogPath = Root+"/data/files", Root+"/data/logs/server.log"
	})
	return map[string]Config{
		"engine-none":          engine,
		"engine-mkcert":        with(func(c *Config) { c.TLS = mkcert }),
		"engine-drupack":       with(func(c *Config) { c.TLS = drupack }),
		"engine-mercure":       with(func(c *Config) { c.Mercure = hub }),
		"engine-project-rules": with(func(c *Config) { c.ProjectRules = Root + "/project/drupack.caddy" }),
		"engine-static":        with(func(c *Config) { c.Static = &Static{Bind: "127.0.0.1", Port: 3788, Root: Root + "/project/html"} }),
		"engine-static-dynamic-mkcert": with(func(c *Config) {
			c.TLS = mkcert
			c.Static = &Static{Bind: "127.0.0.1", Port: 3788, Root: Root + "/project/html", Dynamic: []string{"/api/*", "/search"}}
		}),
		"engine-static-drupack": with(func(c *Config) {
			c.TLS = drupack
			c.Static = &Static{Bind: "127.0.0.1", Port: 3788, Root: Root + "/project/html"}
		}),
		"engine-everything": with(func(c *Config) {
			c.TLS, c.Mercure, c.ProjectRules = drupack, hub, Root+"/project/drupack.caddy"
			c.Static = &Static{Bind: "127.0.0.1", Port: 3788, Root: Root + "/project/html", Dynamic: []string{"/api/*"}}
		}),
		"site-none":    site,
		"site-mkcert":  func() Config { c := site; c.TLS = mkcert; return c }(),
		"site-drupack": func() Config { c := site; c.TLS = drupack; return c }(),
		"site-everything": func() Config {
			c := site
			c.TLS, c.Mercure, c.ProjectRules = drupack, hub, Root+"/app/drupack.caddy"
			c.Static = &Static{Bind: "127.0.0.1", Port: 3788, Root: Root + "/data/static", Dynamic: []string{"/api/*"}}
			return c
		}(),
	}
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
	} {
		if got := quote(value); got != want {
			t.Errorf("quote(%q) = %s, want %s", value, got, want)
		}
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
	c := Config{TLS: tls.Choice{Source: "none"}}
	if got := c.Addresses(3787); got != `"http://:3787"` {
		t.Errorf("none: %s", got)
	}
	c = Config{TLS: tls.Choice{Source: "mkcert", Alias: "a.drupack.site"}}
	want := `"https://a.drupack.site:3788", "https://localhost:3788", "https://127.0.0.1:3788", "https://[::1]:3788"`
	if got := c.Addresses(3788); got != want {
		t.Errorf("mkcert: %s", got)
	}
}

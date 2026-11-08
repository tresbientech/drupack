package main

import (
	"os"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/tls"
)

func TestEngineListen(t *testing.T) {
	for _, row := range []struct {
		listen string
		bind   string
		port   int
		err    string
	}{
		{"127.0.0.1:3787", "127.0.0.1", 3787, ""},
		{"[::1]:80", "::1", 80, ""},
		{"0.0.0.0:65535", "0.0.0.0", 65535, ""},
		{"localhost:9", "localhost", 9, ""},
		{"127.0.0.1:65536", "", 0, "--listen takes IP:PORT, got 127.0.0.1:65536"},
		{"127.0.0.1:123456", "", 0, "--listen takes IP:PORT, got 127.0.0.1:123456"},
		{"127.0.0.1", "", 0, "--listen takes IP:PORT, got 127.0.0.1"},
		{"", "", 0, "--listen takes IP:PORT, got "},
	} {
		bind, port, err := engineListen(row.listen)
		message := ""
		if err != nil {
			message = err.Error()
		}
		if bind != row.bind || port != row.port || message != row.err {
			t.Errorf("engineListen(%q) = %q, %d, %q, want %q, %d, %q", row.listen, bind, port, message, row.bind, row.port, row.err)
		}
	}
}

func TestPlanStart(t *testing.T) {
	for _, row := range []struct {
		name         string
		bind         string
		port         int
		staticListen string
		hasStatic    bool
		host, scheme string
		want         startPlan
		err          string
	}{
		{"http on loopback", "127.0.0.1", 3787, "", false, "127.0.0.1", "http",
			startPlan{Bind: "127.0.0.1", Port: 3787, Host: "127.0.0.1", Scheme: "http", URL: "http://127.0.0.1:3787/", Probe: "http://127.0.0.1:3787/"}, ""},
		{"https on a drupack.site name", "127.0.0.1", 3787, "", false, "shop.drupack.site", "https",
			startPlan{Bind: "127.0.0.1", Port: 3787, Host: "shop.drupack.site", Scheme: "https", URL: "https://shop.drupack.site:3787/", Probe: "https://127.0.0.1:3787/"}, ""},
		{"a wildcard probes its own loopback", "::", 8080, "", false, "::", "http",
			startPlan{Bind: "::", Port: 8080, Host: "::", Scheme: "http", URL: "http://[::]:8080/", Probe: "http://[::1]:8080/"}, ""},
		{"static on the next port", "127.0.0.1", 3787, "", true, "localhost", "http",
			startPlan{Bind: "127.0.0.1", Port: 3787, Host: "localhost", Scheme: "http", URL: "http://localhost:3787/", Probe: "http://127.0.0.1:3787/",
				Static: &staticPlan{Bind: "127.0.0.1", Port: 3788, URL: "http://localhost:3788/"}}, ""},
		{"static on the site address of an IPv6 bind", "::1", 3787, "", true, "::1", "http",
			startPlan{Bind: "::1", Port: 3787, Host: "::1", Scheme: "http", URL: "http://[::1]:3787/", Probe: "http://[::1]:3787/",
				Static: &staticPlan{Bind: "::1", Port: 3788, URL: "http://[::1]:3788/"}}, ""},
		{"static typed", "127.0.0.1", 3787, "0.0.0.0:9000", true, "localhost", "https",
			startPlan{Bind: "127.0.0.1", Port: 3787, Host: "localhost", Scheme: "https", URL: "https://localhost:3787/", Probe: "https://127.0.0.1:3787/",
				Static: &staticPlan{Bind: "0.0.0.0", Port: 9000, URL: "https://localhost:9000/"}}, ""},
		{"static on the site's port", "127.0.0.1", 3787, "127.0.0.1:3787", true, "localhost", "http", startPlan{}, "--static-listen must name a port other than the site's 3787"},
		{"static past the last port", "127.0.0.1", 65535, "", true, "localhost", "http", startPlan{}, "Invalid --static-listen address or port"},
		{"static malformed", "127.0.0.1", 3787, "nine", true, "localhost", "http", startPlan{}, "--static-listen requires IP:PORT, with IPv6 enclosed in brackets"},
		{"static typed for a project with none", "127.0.0.1", 3787, "127.0.0.1:9", false, "localhost", "http", startPlan{}, "--static-listen needs a project whose composer.json sets extra.drupack.static"},
	} {
		got, err := planStart(row.bind, row.port, row.staticListen, row.hasStatic, row.host, row.scheme)
		message := ""
		if err != nil {
			message = err.Error()
		}
		if !reflect.DeepEqual(got, row.want) || message != row.err {
			t.Errorf("%s: planStart = %+v, %q, want %+v, %q", row.name, got, message, row.want, row.err)
		}
	}
}

func TestDrupalDocroot(t *testing.T) {
	packedAs(t, "fixture-site", "dev", false)
	project := t.TempDir()
	write := func(relative string) {
		t.Helper()
		file := filepath.Join(project, filepath.FromSlash(relative))
		if err := os.MkdirAll(filepath.Dir(file), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(file, nil, 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := drupalDocroot(project, "web"); err == nil ||
		err.Error() != project+" is not a Drupal project: "+project+"/web/index.php does not exist. fixture-site serves Drupal alone." {
		t.Errorf("an empty project: %v", err)
	}
	write("web/index.php")
	if _, err := drupalDocroot(project, "web"); err == nil || !strings.HasSuffix(err.Error(), "web/core/lib/Drupal.php does not exist. fixture-site serves Drupal alone.") {
		t.Errorf("a docroot without core: %v", err)
	}
	write("web/core/lib/Drupal.php")
	if got, err := drupalDocroot(project, "web"); err != nil || got != project+"/web" {
		t.Errorf("drupalDocroot(web) = %q, %v", got, err)
	}
	write("index.php")
	write("core/lib/Drupal.php")
	if got, err := drupalDocroot(project, "."); err != nil || got != project {
		t.Errorf("drupalDocroot(.) = %q, %v", got, err)
	}
}

func values(t *testing.T, entries []string) map[string]string {
	t.Helper()
	got := map[string]string{}
	for _, entry := range entries {
		name, value, _ := strings.Cut(entry, "=")
		got[name] = value
	}
	return got
}

// The rows are the variables the engine's Caddyfile reads, per TLS source, static
// setting and Mercure flag.
func TestServerEnvironment(t *testing.T) {
	project := t.TempDir()
	plain := startPlan{Bind: "127.0.0.1", Port: 3787, Probe: "http://127.0.0.1:3787/"}
	withStatic := plain
	withStatic.Static = &staticPlan{Bind: "127.0.0.1", Port: 3788}
	entry := "/cache/folders/f0123456789abcdef"
	docroot := project + "/web"
	base := map[string]string{
		"DRUPACK_RUNTIME_PORT":            "3787",
		"DRUPACK_RUNTIME_BIND":            "127.0.0.1",
		"DRUPACK_RUNTIME_DOCROOT":         docroot,
		"DRUPACK_RUNTIME_ID":              "f0123456789abcdef",
		"DRUPACK_RUNTIME_URL":             "http://127.0.0.1:3787/",
		"DRUPACK_RUNTIME_STOP_RECORD":     entry + "/stop.json",
		"DRUPACK_RUNTIME_ALIAS":           "shop.drupack.site",
		"DRUPACK_RUNTIME_TLS":             "none",
		"DRUPACK_RUNTIME_STATIC_SNIPPET":  "none.caddy",
		"DRUPACK_RUNTIME_MERCURE_SNIPPET": "none.caddy",
		"DRUPACK_RUNTIME_MERCURE_DB":      entry + "/mercure.db",
		"DRUPACK_RUNTIME_PROJECT_SNIPPET": "none.caddy",
	}
	with := func(changes map[string]string) map[string]string {
		want := map[string]string{}
		for name, value := range base {
			want[name] = value
		}
		for name, value := range changes {
			want[name] = value
		}
		return want
	}
	for _, row := range []struct {
		name     string
		composer siteconfig.Composer
		choice   tls.Choice
		plan     startPlan
		want     map[string]string
	}{
		{"plain http", siteconfig.Composer{}, tls.Choice{Source: "none", Alias: "shop.drupack.site"}, plain, with(nil)},
		{"mkcert", siteconfig.Composer{}, tls.Choice{Source: "mkcert", Alias: "shop.drupack.site", Root: "/r.pem", Certificate: "/c.pem", Key: "/k.pem"}, plain,
			with(map[string]string{"DRUPACK_RUNTIME_TLS": "mkcert", "DRUPACK_RUNTIME_TLS_ROOT": "/r.pem", "DRUPACK_RUNTIME_TLS_CERT": "/c.pem", "DRUPACK_RUNTIME_TLS_KEY": "/k.pem"})},
		{"drupack root", siteconfig.Composer{}, tls.Choice{Source: "drupack", Alias: "shop.drupack.site", Root: "/r.pem", RootKey: "/r.key", Authority: "Drupack local root"}, plain,
			with(map[string]string{"DRUPACK_RUNTIME_TLS": "drupack", "DRUPACK_RUNTIME_TLS_ROOT": "/r.pem", "DRUPACK_RUNTIME_TLS_ROOT_KEY": "/r.key", "DRUPACK_RUNTIME_TLS_CA": "Drupack local root"})},
		{"mercure", siteconfig.Composer{Mercure: true}, tls.Choice{Source: "none", Alias: "shop.drupack.site"}, plain,
			with(map[string]string{"DRUPACK_RUNTIME_MERCURE_SNIPPET": "mercure.caddy"})},
		{"static without dynamic paths", siteconfig.Composer{Static: &siteconfig.Static{}}, tls.Choice{Source: "none", Alias: "shop.drupack.site"}, withStatic,
			with(map[string]string{
				"DRUPACK_RUNTIME_STATIC_ROOT": project + "/html", "DRUPACK_RUNTIME_STATIC_SNIPPET": "static-site-none.caddy",
				"DRUPACK_RUNTIME_STATIC_BIND": "127.0.0.1", "DRUPACK_RUNTIME_STATIC_PORT": "3788", "DRUPACK_RUNTIME_STATIC_DYNAMIC_SNIPPET": "none.caddy"})},
		{"static with dynamic paths over mkcert", siteconfig.Composer{Static: &siteconfig.Static{Dynamic: []string{"/api/*", "/search"}}},
			tls.Choice{Source: "mkcert", Alias: "shop.drupack.site", Root: "/r.pem", Certificate: "/c.pem", Key: "/k.pem"}, withStatic,
			with(map[string]string{
				"DRUPACK_RUNTIME_TLS": "mkcert", "DRUPACK_RUNTIME_TLS_ROOT": "/r.pem", "DRUPACK_RUNTIME_TLS_CERT": "/c.pem", "DRUPACK_RUNTIME_TLS_KEY": "/k.pem",
				"DRUPACK_RUNTIME_STATIC_ROOT": project + "/html", "DRUPACK_RUNTIME_STATIC_SNIPPET": "static-site-mkcert.caddy",
				"DRUPACK_RUNTIME_STATIC_BIND": "127.0.0.1", "DRUPACK_RUNTIME_STATIC_PORT": "3788",
				"DRUPACK_RUNTIME_STATIC_DYNAMIC_SNIPPET": "static-dynamic.caddy", "DRUPACK_RUNTIME_STATIC_DYNAMIC": "/api/* /search"})},
	} {
		entries, err := serverEnvironment(project, docroot, entry, row.composer, row.choice, row.plan)
		if err != nil {
			t.Errorf("%s: %v", row.name, err)
			continue
		}
		if got := values(t, entries); !reflect.DeepEqual(got, row.want) {
			t.Errorf("%s: got %v, want %v", row.name, got, row.want)
		}
	}

	snippet := filepath.Join(project, "drupack.caddy")
	if err := os.WriteFile(snippet, nil, 0o644); err != nil {
		t.Fatal(err)
	}
	entries, err := serverEnvironment(project, docroot, entry, siteconfig.Composer{}, tls.Choice{Source: "none"}, plain)
	if err != nil || values(t, entries)["DRUPACK_RUNTIME_PROJECT_SNIPPET"] != snippet {
		t.Errorf("a project with a drupack.caddy: %v, %v", entries, err)
	}
	_, err = serverEnvironment(project, project+`/we"b`, entry, siteconfig.Composer{}, tls.Choice{Source: "none"}, plain)
	if err == nil || err.Error() != `DRUPACK_RUNTIME_DOCROOT must not contain a double quote: `+project+`/we"b` {
		t.Errorf("a docroot holding a double quote: %v", err)
	}
	if !slices.IsSorted(entries) {
		t.Errorf("the entries are not in name order: %v", entries)
	}
}

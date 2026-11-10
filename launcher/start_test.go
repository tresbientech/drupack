package main

import (
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/caddyfile"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
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
		got, err := planStart(row.bind, row.port, row.staticListen, row.hasStatic, "project", row.host, row.scheme)
		message := ""
		if err != nil {
			message = err.Error()
		}
		if !reflect.DeepEqual(got, row.want) || message != row.err {
			t.Errorf("%s: planStart = %+v, %q, want %+v, %q", row.name, got, message, row.want, row.err)
		}
	}
}

func TestRecordKeepsTheStaticListener(t *testing.T) {
	for _, row := range []struct {
		bind      string
		hasStatic bool
		want      string
	}{
		{"127.0.0.1", true, "127.0.0.1:3788"},
		{"::1", true, "[::1]:3788"},
		{"127.0.0.1", false, ""},
	} {
		plan, err := planStart(row.bind, 3787, "", row.hasStatic, "site", "localhost", "http")
		if err != nil {
			t.Fatal(err)
		}
		if got := plan.record().StaticListen; got != row.want {
			t.Errorf("%s, static %v: StaticListen = %q, want %q", row.bind, row.hasStatic, got, row.want)
		}
	}
}

func TestNameHint(t *testing.T) {
	if got := nameHint(tls.Choice{Host: "shop.drupack.site", Alias: "shop.drupack.site"}); got != "" {
		t.Errorf("a resolved name prints %q", got)
	}
	choice := tls.Choice{Host: "localhost", Alias: "shop.drupack.site", Reason: "answered 0.0.0.0"}
	if got, want := nameHint(choice), "Name: shop.drupack.site did not resolve to 127.0.0.1 (answered 0.0.0.0), so the URL uses localhost.\n"; got != want {
		t.Errorf("nameHint = %q, want %q", got, want)
	}
	unnamed := tls.Choice{Host: "127.0.0.1", Alias: "project.drupack.site", Reason: "no answer"}
	if got, want := nameHint(unnamed), "Name: project.drupack.site did not resolve to 127.0.0.1 (no answer), so the URL uses 127.0.0.1.\n"; got != want {
		t.Errorf("nameHint on the bind address = %q, want %q", got, want)
	}
}

func TestExecServerRefusesAHubFileWithoutTheMercureKey(t *testing.T) {
	rules := t.TempDir()
	hub := filepath.Join(rules, "drupack.mercure.caddy")
	if err := os.WriteFile(hub, []byte("anonymous\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	err := execServer(Facts{}, state.Dir{}, nil, caddyfile.Config{}, tls.Choice{}, startPlan{}, rules, "drupack", rules)
	want := hub + " holds Mercure hub directives, and " + rules + "/composer.json does not set extra.drupack.mercure to true"
	if err == nil || err.Error() != want {
		t.Errorf("execServer = %v, want %q", err, want)
	}
}

func TestTrustHint(t *testing.T) {
	saved := siteName
	siteName = "demo"
	t.Cleanup(func() { siteName = saved })
	hint := "HTTPS: run \"demo trust\" once to serve this site over HTTPS.\n"
	for _, row := range []struct {
		name   string
		dir    string
		choice tls.Choice
		want   string
	}{
		{"a loopback start with no root", "/tls", tls.Choice{Source: "none", Eligible: true}, hint},
		{"a start that serves HTTPS", "/tls", tls.Choice{Source: "drupack", Eligible: true}, ""},
		{"a start another computer reaches", "/tls", tls.Choice{Source: "none"}, ""},
		{"an account with no config folder", "", tls.Choice{Source: "none", Eligible: true}, ""},
	} {
		if got := trustHint(Launch{TLSDir: row.dir}, row.choice); got != row.want {
			t.Errorf("%s: trustHint = %q, want %q", row.name, got, row.want)
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

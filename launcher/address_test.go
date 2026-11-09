package main

import (
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

func TestServed(t *testing.T) {
	plain := siteTarget(siteconfig.Site{Port: 1})
	engine := Site{DefaultListen: "127.0.0.1:3787", Engine: true}
	record := state.Listener{Listen: "127.0.0.1:8080", Host: "shop.drupack.site", Scheme: "https", FilesDir: "/recorded/files"}
	withStatic := record
	withStatic.StaticListen = "127.0.0.1:8081"
	shop := Address{record, "127.0.0.1", 8080, true}
	with := func(change func(*Address)) Address {
		a := shop
		change(&a)
		return a
	}

	for _, row := range []struct {
		name      string
		site      Site
		record    *state.Listener
		raw       string
		typed     state.Listener
		noun      string // a row that names the project asks for the static plan
		hasStatic bool
		want      Address
		plan      startPlan
		err       string
	}{
		{name: "no record", site: plain,
			want: Address{state.Listener{Listen: "127.0.0.1:1", Host: "localhost", Scheme: "http"}, "127.0.0.1", 1, false}},
		{name: "an engine with no record", site: engine,
			want: Address{state.Listener{Listen: "127.0.0.1:3787", Host: "localhost", Scheme: "http"}, "127.0.0.1", 3787, false}},
		{name: "record alone", site: plain, record: &record, want: shop},
		{name: "typed listen over record", site: plain, record: &record, typed: state.Listener{Listen: "127.0.0.1:9000"},
			want: with(func(a *Address) { a.Port, a.Listen = 9000, "127.0.0.1:9000" })},
		{name: "typed static listen over record", site: plain, record: &withStatic, typed: state.Listener{StaticListen: "127.0.0.1:9001"},
			noun: "site", hasStatic: true,
			want: with(func(a *Address) { a.StaticListen = "127.0.0.1:9001" }),
			plan: mustPlan(t, "127.0.0.1", 8080, "127.0.0.1:9001", true, "shop.drupack.site", "https")},
		{name: "typed host over record", site: plain, record: &record, typed: state.Listener{Host: "blog.drupack.site"},
			want: with(func(a *Address) { a.Host = "blog.drupack.site" })},
		{name: "typed files directory over record", site: plain, record: &record, typed: state.Listener{FilesDir: "/typed/files"},
			want: with(func(a *Address) { a.FilesDir = "/typed/files" })},
		{name: "a hostname record read with any host", site: engine, record: &state.Listener{Listen: "localhost:8080", Host: "localhost", Scheme: "http"},
			want: Address{state.Listener{Listen: "localhost:8080", Host: "localhost", Scheme: "http"}, "localhost", 8080, true}},
		{name: "a record with a static listener", site: plain, record: &withStatic, noun: "site", hasStatic: true,
			want: with(func(a *Address) { a.StaticListen = "127.0.0.1:8081" }),
			plan: mustPlan(t, "127.0.0.1", 8080, "127.0.0.1:8081", true, "shop.drupack.site", "https")},
		{name: "a record with no static listener", site: plain, record: &record, noun: "site", hasStatic: true, want: shop,
			plan: mustPlan(t, "127.0.0.1", 8080, "", true, "shop.drupack.site", "https")},
		{name: "a hostname record with no static listener", site: engine, record: &state.Listener{Listen: "localhost:8080", Host: "localhost", Scheme: "http"},
			noun: "project", hasStatic: true,
			err: "--static-listen requires IP:PORT, with IPv6 enclosed in brackets"},

		{name: "an unreadable record", site: plain, raw: "not json",
			err: "Cannot read the recorded listener: {dir}/listener. Remove that file, then start fixture-site again to record it."},
		{name: "a record with no host", site: plain, raw: `{"listen":"127.0.0.1:1"}`,
			err: "Recorded listener has no host"},
		{name: "a listener that is no address", site: plain, typed: state.Listener{Listen: "localhost:80"},
			err: "--listen requires IP:PORT, with IPv6 enclosed in brackets"},
		{name: "a listener port out of range", site: plain, typed: state.Listener{Listen: "127.0.0.1:70000"},
			err: "Invalid --listen address or port"},
		{name: "a static listener on the site's port", site: plain, typed: state.Listener{Listen: "127.0.0.1:5", StaticListen: "127.0.0.1:5"},
			noun: "site", hasStatic: true,
			err: "--static-listen must name a port other than the site's 5"},
		{name: "a static listener on a site without one", site: plain, typed: state.Listener{StaticListen: "127.0.0.1:5"},
			noun: "site",
			err:  "--static-listen needs a site whose composer.json sets extra.drupack.static"},
		{name: "a bad listener with a bad host", site: plain, typed: state.Listener{Listen: "localhost:80", Host: "not a host"},
			err: "--listen requires IP:PORT, with IPv6 enclosed in brackets"},
		{name: "a host that is no name", site: plain, typed: state.Listener{Host: "not a host"},
			err: "Invalid --host value"},
	} {
		t.Run(row.name, func(t *testing.T) {
			dir := t.TempDir()
			switch {
			case row.record != nil:
				if err := state.At(dir).WriteListener(*row.record); err != nil {
					t.Fatal(err)
				}
			case row.raw != "":
				if err := os.WriteFile(filepath.Join(dir, "listener"), []byte(row.raw), 0o600); err != nil {
					t.Fatal(err)
				}
			}
			got, err := Served(state.At(dir), "fixture-site", row.site, row.typed)
			var plan startPlan
			if err == nil && row.noun != "" {
				plan, err = got.Plan(row.hasStatic, row.noun)
			}
			if row.err != "" {
				want := strings.ReplaceAll(row.err, "{dir}", dir)
				if err == nil || err.Error() != want {
					t.Errorf("error = %v, want %q", err, want)
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			if !reflect.DeepEqual(got, row.want) {
				t.Errorf("Served = %+v, want %+v", got, row.want)
			}
			if !reflect.DeepEqual(plan, row.plan) {
				t.Errorf("Plan = %+v, want %+v", plan, row.plan)
			}
			if got.Mercure() != mercureURL(row.want.Scheme, row.want.Bind, row.want.Port) {
				t.Errorf("Mercure %s", got.Mercure())
			}
		})
	}
}

func mustPlan(t *testing.T, bind string, port int, staticListen string, hasStatic bool, host, scheme string) startPlan {
	t.Helper()
	plan, err := planStart(bind, port, staticListen, hasStatic, "site", host, scheme)
	if err != nil {
		t.Fatal(err)
	}
	return plan
}

func TestSiteListen(t *testing.T) {
	for _, row := range []struct {
		name  string
		site  Site
		typed string
		bind  string
		port  int
		err   string
	}{
		{name: "a site's value", site: siteTarget(siteconfig.Site{Port: 7}), typed: "[::1]:9", bind: "::1", port: 9},
		{name: "a site's hostname", site: siteTarget(siteconfig.Site{Port: 7}), typed: "localhost:9",
			err: "--listen requires IP:PORT, with IPv6 enclosed in brackets"},
		{name: "the engine's value", site: engineTarget(), typed: "localhost:9", bind: "localhost", port: 9},
		{name: "the engine's refusal", site: engineTarget(), typed: "localhost:x", err: "--listen takes IP:PORT, got localhost:x"},
		{name: "the engine's empty value", site: engineTarget(), err: "--listen takes IP:PORT, got "},
	} {
		t.Run(row.name, func(t *testing.T) {
			bind, port, err := row.site.Listen(row.typed)
			if row.err != "" {
				if err == nil || err.Error() != row.err {
					t.Errorf("error = %v, want %q", err, row.err)
				}
				return
			}
			if err != nil || bind != row.bind || port != row.port {
				t.Errorf("Listen = %q, %d, %v, want %q, %d", bind, port, err, row.bind, row.port)
			}
		})
	}
}

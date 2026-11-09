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
	static := siteTarget(siteconfig.Site{Port: 1, Static: &siteconfig.Static{}})
	engine := Site{DefaultListen: "127.0.0.1:3787", Noun: "project", AnyHost: true}
	record := state.Listener{Listen: "127.0.0.1:8080", Host: "shop.drupack.site", Scheme: "https", FilesDir: "/recorded/files"}
	withStatic := record
	withStatic.StaticListen = "127.0.0.1:8081"

	for _, row := range []struct {
		name   string
		site   Site
		record *state.Listener
		raw    string
		typed  Typed
		want   Address
		err    string
	}{
		{name: "no record", site: plain,
			want: Address{startPlan: mustPlan(t, "127.0.0.1", 1, "", false, "localhost", "http")}},
		{name: "record alone", site: plain, record: &record,
			want: Address{startPlan: mustPlan(t, "127.0.0.1", 8080, "", false, "shop.drupack.site", "https"), Recorded: true, FilesDir: "/recorded/files"}},
		{name: "typed listen over record", site: plain, record: &record, typed: Typed{Listen: "127.0.0.1:9000"},
			want: Address{startPlan: mustPlan(t, "127.0.0.1", 9000, "", false, "shop.drupack.site", "https"), Recorded: true, FilesDir: "/recorded/files"}},
		{name: "typed static listen over record", site: static, record: &withStatic, typed: Typed{StaticListen: "127.0.0.1:9001"},
			want: Address{startPlan: mustPlan(t, "127.0.0.1", 8080, "127.0.0.1:9001", true, "shop.drupack.site", "https"), Recorded: true, StaticRecorded: true, FilesDir: "/recorded/files"}},
		{name: "typed host over record", site: plain, record: &record, typed: Typed{Host: "blog.drupack.site"},
			want: Address{startPlan: mustPlan(t, "127.0.0.1", 8080, "", false, "blog.drupack.site", "https"), Recorded: true, FilesDir: "/recorded/files"}},
		{name: "typed files directory over record", site: plain, record: &record, typed: Typed{FilesDir: "/typed/files"},
			want: Address{startPlan: mustPlan(t, "127.0.0.1", 8080, "", false, "shop.drupack.site", "https"), Recorded: true, FilesDir: "/typed/files"}},
		{name: "a hostname record read with any host", site: engine, record: &state.Listener{Listen: "localhost:8080", Host: "localhost", Scheme: "http"},
			want: Address{startPlan: mustPlan(t, "localhost", 8080, "", false, "localhost", "http"), Recorded: true}},
		{name: "a record with a static listener", site: static, record: &withStatic,
			want: Address{startPlan: mustPlan(t, "127.0.0.1", 8080, "127.0.0.1:8081", true, "shop.drupack.site", "https"), Recorded: true, StaticRecorded: true, FilesDir: "/recorded/files"}},
		{name: "a record with no static listener", site: static, record: &record,
			want: Address{startPlan: mustPlan(t, "127.0.0.1", 8080, "", true, "shop.drupack.site", "https"), Recorded: true, FilesDir: "/recorded/files"}},

		{name: "an unreadable record", site: plain, raw: "not json",
			err: "Cannot read the recorded listener: {dir}/listener. Remove that file, then start fixture-site again to record it."},
		{name: "a record with no host", site: plain, raw: `{"listen":"127.0.0.1:1"}`,
			err: "Recorded listener has no host"},
		{name: "a listener that is no address", site: plain, typed: Typed{Listen: "localhost:80"},
			err: "--listen requires IP:PORT, with IPv6 enclosed in brackets"},
		{name: "a listener port out of range", site: plain, typed: Typed{Listen: "127.0.0.1:70000"},
			err: "Invalid --listen address or port"},
		{name: "a static listener on the site's port", site: static, typed: Typed{Listen: "127.0.0.1:5", StaticListen: "127.0.0.1:5"},
			err: "--static-listen must name a port other than the site's 5"},
		{name: "a static listener on a site without one", site: plain, typed: Typed{StaticListen: "127.0.0.1:5"},
			err: "--static-listen needs a site whose composer.json sets extra.drupack.static"},
		{name: "a host that is no name", site: plain, typed: Typed{Host: "not a host"},
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

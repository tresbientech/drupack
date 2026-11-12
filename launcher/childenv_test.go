package main

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/caddyfile"
)

func TestEnvKinds(t *testing.T) {
	separator := string(os.PathListSeparator)
	launch := Launch{
		Entry: "/runtime/dir/frankenphp", Application: "/cache/app", Name: "demo",
		Parent: []string{"PATH=/bin", "DRUPACK_RUNTIME_OPEN=http://stale/", "DRUPACK_ADMIN_PASSWORD=inherited", "DRUPACK_RUNTIME_STATIC_ROOT=/stale"},
	}
	engine := launch
	engine.Engine = true
	site := Facts{
		Launch: launch, Inputs: []string{"DRUPACK_ADMIN_PASSWORD=typed", "DRUPACK_DATABASE=mysql"},
		Data: "/data", Files: "/data/files", Scratch: "/data/runtime", Host: "::1", URL: "http://[::1]:8080/",
	}
	hub := &caddyfile.Mercure{URL: "http://localhost:8080/.well-known/mercure", Publisher: "publisher", Subscriber: "subscriber"}
	siteVariables := map[string]string{
		"DRUPACK_RUNTIME_DATA_DIR": "/data", "DRUPACK_RUNTIME_FILES_DIR": "/data/files", "DRUPACK_RUNTIME_HOST": "[::1]",
		"DRUSH_OPTIONS_URI": "http://[::1]:8080/", "TMPDIR": "/data/runtime", "TEMP": "/data/runtime", "TMP": "/data/runtime",
		"XDG_DATA_HOME": "/data/runtime", "XDG_CONFIG_HOME": "/data/runtime",
	}
	with := func(base map[string]string, more map[string]string) map[string]string {
		merged := map[string]string{}
		for _, values := range []map[string]string{base, more} {
			for name, value := range values {
				merged[name] = value
			}
		}
		return merged
	}
	laid := site
	laid.SiteApp, laid.Bin, laid.Script, laid.CAFile, laid.Hub, laid.StaticRoot = "/data/app", "/data/app", "/data/app/vendor/drush/drush/drush.php", "/data/runtime/ca.pem", hub, "/data/static"
	laidVariables := with(siteVariables, map[string]string{
		"DRUPACK_RUNTIME_APP_DIR": "/data/app", "PATH": filepath.FromSlash("/data/app/bin") + separator + "/bin",
		"DRUPACK_DRUSH_SCRIPT": "/data/app/vendor/drush/drush/drush.php", "DRUPACK_CA_FILE": "/data/runtime/ca.pem",
		"DRUPACK_MERCURE_PUBLISHER_SECRET": "publisher", "DRUPACK_MERCURE_SUBSCRIBER_SECRET": "subscriber", "DRUPACK_MERCURE_URL": hub.URL,
		"DRUPACK_RUNTIME_STATIC_ROOT": "/data/static",
	})
	serving := laid
	serving.Open, serving.Alias, serving.StopRecord = "http://[::1]:8080/user/reset/1", "demo.drupack.site", "/data/stop.json"
	watch := map[string]string{"DRUPACK_RUNTIME_STOP_RECORD": "/data/stop.json"}
	folder := Facts{Launch: engine, CAFile: "/entry/ca.pem", Hub: hub, Bin: "/cache/app", Script: "/project/vendor/drush/drush/drush.php"}
	folderVariables := map[string]string{
		"PATH": filepath.FromSlash("/cache/app/bin") + separator + "/bin", "DRUPACK_DRUSH_SCRIPT": "/project/vendor/drush/drush/drush.php",
		"DRUPACK_CA_FILE": "/entry/ca.pem", "DRUPACK_MERCURE_URL": hub.URL, "DRUPACK_ADMIN_PASSWORD": "inherited",
	}
	folderServing := folder
	folderServing.Script, folderServing.StopRecord = "", "/data/stop.json"
	folderOpening := folderServing
	folderOpening.Open = "http://127.0.0.1:3787/core/install.php"
	for _, row := range []struct {
		name  string
		facts Facts
		kind  Kind
		want  map[string]string
		lacks []string
	}{
		{"a site's check reads the typed inputs and no Site data", Facts{Launch: launch, Inputs: site.Inputs}, Operate,
			map[string]string{"DRUPACK_ADMIN_PASSWORD": "typed", "DRUPACK_DATABASE": "mysql", "PATH": "/bin", "DRUPACK_RUNTIME_STATIC_ROOT": "/stale", "DRUPACK_RUNTIME_APP_DIR": "/cache/app"},
			[]string{"DRUPACK_RUNTIME_DATA_DIR", "DRUSH_OPTIONS_URI", "DRUPACK_DRUSH_SCRIPT"}},
		{"a site's prepare reads the inputs over Site data", laid, Operate,
			with(laidVariables, map[string]string{"DRUPACK_ADMIN_PASSWORD": "typed", "DRUPACK_DATABASE": "mysql"}), nil},
		{"a login mint for a served site runs the release's Drush off PATH", Facts{Launch: launch, Data: site.Data, Files: site.Files, Scratch: site.Scratch, Host: site.Host, URL: site.URL, Script: "/cache/app/vendor/drush/drush/drush.php"}, Drush,
			with(siteVariables, map[string]string{"PATH": "/bin", "DRUPACK_DRUSH_SCRIPT": "/cache/app/vendor/drush/drush/drush.php", "DRUPACK_RUNTIME_STATIC_ROOT": "/stale"}), nil},
		{"a site's drush keeps the inherited password and reads no typed input", laid, Drush,
			with(laidVariables, map[string]string{"DRUPACK_ADMIN_PASSWORD": "inherited", "DRUPACK_RUNTIME_OPEN": "http://stale/"}), []string{"DRUPACK_DATABASE"}},
		{"a site's export reads what its drush reads", laid, Export,
			with(laidVariables, map[string]string{"DRUPACK_ADMIN_PASSWORD": "inherited"}), []string{"DRUPACK_DATABASE"}},
		{"a site's server loses the password and opens the link", serving, Server,
			with(with(laidVariables, watch), map[string]string{"DRUPACK_RUNTIME_OPEN": "http://[::1]:8080/user/reset/1", "DRUPACK_RUNTIME_ALIAS": "demo.drupack.site"}),
			[]string{"DRUPACK_ADMIN_PASSWORD", "DRUPACK_DATABASE"}},
		{"the engine's check reads the reader's environment", Facts{Launch: engine}, Operate,
			map[string]string{"PATH": "/bin", "DRUPACK_ADMIN_PASSWORD": "inherited"}, []string{"DRUPACK_RUNTIME_APP_DIR", "DRUPACK_DRUSH_SCRIPT"}},
		{"the engine's drush reads the last start's bundle and hub", folder, Drush, folderVariables, []string{"DRUPACK_RUNTIME_DATA_DIR", "DRUPACK_RUNTIME_APP_DIR"}},
		{"the engine's export reads what its drush reads", folder, Export, folderVariables, nil},
		{"the engine's core call leads PATH and names no Drush", Facts{Launch: engine, Bin: "/cache/app"}, Drush,
			map[string]string{"PATH": filepath.FromSlash("/cache/app/bin") + separator + "/bin"}, []string{"DRUPACK_DRUSH_SCRIPT"}},
		{"an engine server with no page drops the inherited one and keeps the password", folderServing, Server,
			with(watch, map[string]string{"DRUPACK_ADMIN_PASSWORD": "inherited", "DRUPACK_CA_FILE": "/entry/ca.pem"}),
			[]string{"DRUPACK_RUNTIME_OPEN", "DRUPACK_RUNTIME_ALIAS", "DRUPACK_DRUSH_SCRIPT"}},
		{"an engine server opens its page", folderOpening, Server,
			with(watch, map[string]string{"DRUPACK_RUNTIME_OPEN": "http://127.0.0.1:3787/core/install.php", "DRUPACK_CA_FILE": "/entry/ca.pem"}),
			[]string{"DRUPACK_RUNTIME_ALIAS"}},
	} {
		got := environmentOf(Command{Env: row.facts.Env(row.kind)})
		for name, want := range row.want {
			if got[name] != want {
				t.Errorf("%s: %s = %q, want %q", row.name, name, got[name], want)
			}
		}
		for _, name := range row.lacks {
			if value, set := got[name]; set {
				t.Errorf("%s: %s = %q, want it unset", row.name, name, value)
			}
		}
	}
}

// TestEnvDeclared holds Env to runtime/environment.txt: each kind and mode emits the
// names the file gives it, from a start that knows everything its mode can know.
func TestEnvDeclared(t *testing.T) {
	text, err := os.ReadFile("../runtime/environment.txt")
	if err != nil {
		t.Fatal(err)
	}
	hub := &caddyfile.Mercure{}
	engine := Facts{Launch: Launch{Engine: true}, CAFile: "x", Hub: hub, Bin: "x", Script: "x", StopRecord: "x", Open: "x"}
	site := engine
	site.Engine = false
	site.Data, site.StaticRoot, site.SiteApp, site.Alias = "x", "x", "x", "x"
	kinds := map[string]Kind{"reader": Reader, "server": Server, "operate": Operate, "drush": Drush, "export": Export}
	for mode, facts := range map[string]Facts{"site": site, "engine": engine} {
		for kind, k := range kinds {
			var want, got []string
			for _, line := range strings.Split(string(text), "\n") {
				fields := strings.Fields(line)
				if len(fields) == 0 || fields[0] == "#" {
					continue
				}
				name := fields[0]
				if !strings.HasPrefix(name, "DRUPACK_") && !strings.HasPrefix(name, "DRUSH_") {
					name = "DRUPACK_RUNTIME_" + name
				}
				column := func(key string) []string {
					for _, field := range fields[1:] {
						if value, found := strings.CutPrefix(field, key+"="); found {
							return strings.Split(value, ",")
						}
					}
					return nil
				}
				if slices.Contains(column("kinds"), kind) && slices.Contains(column("modes"), mode) {
					want = append(want, name)
				}
			}
			for _, entry := range facts.Env(k) {
				if name, _, _ := strings.Cut(entry, "="); strings.HasPrefix(name, "DRUPACK_") || strings.HasPrefix(name, "DRUSH_") {
					got = append(got, name)
				}
			}
			slices.Sort(want)
			slices.Sort(got)
			if !slices.Equal(got, want) {
				t.Errorf("%s %s emits %v, and environment.txt declares %v", mode, kind, got, want)
			}
		}
	}
}

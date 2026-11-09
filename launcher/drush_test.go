package main

import (
	"os"
	"path/filepath"
	goruntime "runtime"
	"slices"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

// realDir is a temporary directory with its links resolved, as the launcher resolves Site data.
func realDir(t *testing.T) string {
	t.Helper()
	dir, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	return dir
}

func put(t *testing.T, path, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
}

func environmentOf(c Command) map[string]string {
	values := map[string]string{}
	for _, entry := range c.Env {
		name, value, _ := strings.Cut(entry, "=")
		values[name] = value
	}
	return values
}

// siteFixture is a Packaged site's release application, the reader's directory and a Site data holding a site.
type siteFixture struct {
	application, cwd, data string
}

func newSite(t *testing.T, site siteconfig.Site) siteFixture {
	t.Helper()
	root := realDir(t)
	f := siteFixture{application: filepath.Join(root, "app"), cwd: filepath.Join(root, "reader"), data: filepath.Join(root, "reader", "data")}
	if err := os.MkdirAll(f.application, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := siteconfig.Write(site, f.application); err != nil {
		t.Fatal(err)
	}
	put(t, filepath.Join(f.data, "settings.php"), "<?php")
	return f
}

func (f siteFixture) launch() Launch {
	return Launch{
		Args:        []string{"SITE", "drush"},
		Parent:      []string{"PATH=/usr/bin", "KEEP=1", "DRUPACK_DB_HOST=inherited"},
		Entry:       "/runtime/frankenphp",
		Application: f.application,
		Name:        "fixture-site",
		AppChecksum: "release-1",
	}
}

func (f siteFixture) record(t *testing.T, json string) {
	t.Helper()
	put(t, filepath.Join(f.data, "listener"), json)
}

func parseLine(t *testing.T, engine bool, words ...string) command {
	t.Helper()
	c, err := parse(append([]string{"SITE"}, words...), engine, func(string) (string, bool) { return "", false })
	if err != nil {
		t.Fatal(err)
	}
	return c
}

func parseDrush(t *testing.T, engine bool, words ...string) command {
	t.Helper()
	return parseLine(t, engine, append([]string{"drush"}, words...)...)
}

// drushPlan returns the call main runs for a drush command in cwd.
func drushPlan(l Launch, c command, cwd string) (Command, error) {
	if l.Engine {
		return engineDrush(Facts{Launch: l}, c, cwd)
	}
	return siteDrush(Facts{Launch: l}, c, cwd)
}

func TestSiteDrushEnvironment(t *testing.T) {
	scratch := func(f siteFixture) string { return runtime.Canonical(filepath.Join(f.data, "runtime")) }
	for _, row := range []struct {
		name    string
		site    siteconfig.Site
		prepare func(t *testing.T, f siteFixture)
		words   []string
		want    func(f siteFixture) map[string]string
		absent  []string
	}{
		{
			name:  "no record addresses localhost on the site's port",
			site:  siteconfig.Site{Port: 8123},
			words: []string{"status"},
			want: func(f siteFixture) map[string]string {
				return map[string]string{
					"DRUPACK_RUNTIME_DATA_DIR":  runtime.Canonical(f.data),
					"DRUPACK_RUNTIME_FILES_DIR": runtime.Canonical(filepath.Join(f.data, "files")),
					"DRUPACK_RUNTIME_HOST":      "localhost",
					"DRUSH_OPTIONS_URI":         "http://localhost:8123/",
					"TMPDIR":                    scratch(f),
					"TEMP":                      scratch(f),
					"TMP":                       scratch(f),
					"XDG_DATA_HOME":             scratch(f),
					"XDG_CONFIG_HOME":           scratch(f),
					"DRUPACK_RUNTIME_APP_DIR":   runtime.Canonical(f.application),
					"DRUPACK_DRUSH_SCRIPT":      runtime.Canonical(filepath.Join(f.application, "vendor/drush/drush/drush.php")),
					"PATH":                      filepath.Join(f.application, "bin") + string(os.PathListSeparator) + "/usr/bin",
					"KEEP":                      "1",
					"DRUPACK_DB_HOST":           "inherited",
				}
			},
			absent: []string{"DRUPACK_RUNTIME_STATIC_ROOT", "DRUPACK_MERCURE_URL", "DRUPACK_MERCURE_PUBLISHER_SECRET"},
		},
		{
			name: "an HTTPS record brings its scheme, files directory and bundle",
			site: siteconfig.Site{Port: 8123},
			prepare: func(t *testing.T, f siteFixture) {
				f.record(t, `{"listen":"[::1]:9000","host":"::1","files-dir":"`+runtime.Canonical(filepath.Join(filepath.Dir(f.data), "pub"))+`","scheme":"https","static-listen":null}`)
				put(t, filepath.Join(f.data, "runtime", "ca-bundle.pem"), "roots")
				if err := os.MkdirAll(filepath.Join(filepath.Dir(f.data), "pub"), 0o700); err != nil {
					t.Fatal(err)
				}
			},
			words: []string{"status"},
			want: func(f siteFixture) map[string]string {
				return map[string]string{
					"DRUPACK_RUNTIME_FILES_DIR": runtime.Canonical(filepath.Join(filepath.Dir(f.data), "pub")),
					"DRUPACK_RUNTIME_HOST":      "[::1]",
					"DRUSH_OPTIONS_URI":         "https://[::1]:9000/",
					"DRUPACK_CA_FILE":           runtime.Canonical(filepath.Join(f.data, "runtime", "ca-bundle.pem")),
				}
			},
		},
		{
			name: "an option overrides the record, and a relative path starts at the reader's directory",
			site: siteconfig.Site{Port: 8123},
			prepare: func(t *testing.T, f siteFixture) {
				f.record(t, `{"listen":"127.0.0.1:9000","host":"recorded.test","scheme":"https"}`)
			},
			words: []string{"--listen", "127.0.0.1:9100", "--host=typed.test", "--files-dir", "pub", "status"},
			want: func(f siteFixture) map[string]string {
				return map[string]string{
					"DRUPACK_RUNTIME_FILES_DIR": runtime.Canonical(filepath.Join(f.cwd, "pub")),
					"DRUPACK_RUNTIME_HOST":      "typed.test",
					"DRUSH_OPTIONS_URI":         "https://typed.test:9100/",
				}
			},
		},
		{
			name:  "a static site gets Tome's directory",
			site:  siteconfig.Site{Port: 8123, Static: &siteconfig.Static{}},
			words: []string{"status"},
			want: func(f siteFixture) map[string]string {
				return map[string]string{"DRUPACK_RUNTIME_STATIC_ROOT": runtime.Canonical(filepath.Join(f.data, "static"))}
			},
		},
		{
			name: "a Mercure site with secrets gets the hub, addressed through the wildcard's loopback",
			site: siteconfig.Site{Port: 8123, Mercure: true},
			prepare: func(t *testing.T, f siteFixture) {
				f.record(t, `{"listen":"0.0.0.0:9000","host":"localhost","scheme":"http"}`)
				put(t, filepath.Join(f.data, "mercure_publisher_secret"), "pub")
				put(t, filepath.Join(f.data, "mercure_subscriber_secret"), "sub")
			},
			words: []string{"status"},
			want: func(f siteFixture) map[string]string {
				return map[string]string{
					"DRUPACK_MERCURE_PUBLISHER_SECRET":  "pub",
					"DRUPACK_MERCURE_SUBSCRIBER_SECRET": "sub",
					"DRUPACK_MERCURE_URL":               "http://127.0.0.1:9000/.well-known/mercure",
				}
			},
		},
		{
			name: "a Mercure site no start has served gets no hub",
			site: siteconfig.Site{Port: 8123, Mercure: true},
			prepare: func(t *testing.T, f siteFixture) {
				put(t, filepath.Join(f.data, "mercure_publisher_secret"), "pub")
			},
			words:  []string{"status"},
			want:   func(siteFixture) map[string]string { return nil },
			absent: []string{"DRUPACK_MERCURE_URL", "DRUPACK_MERCURE_PUBLISHER_SECRET", "DRUPACK_MERCURE_SUBSCRIBER_SECRET"},
		},
		{
			name: "a site with writable directories runs its own application",
			site: siteconfig.Site{Port: 8123, Writable: []string{"web/sites/default/files"}},
			prepare: func(t *testing.T, f siteFixture) {
				put(t, filepath.Join(f.data, "app", ".release"), "release-1")
			},
			words: []string{"status"},
			want: func(f siteFixture) map[string]string {
				return map[string]string{
					"DRUPACK_RUNTIME_APP_DIR": runtime.Canonical(filepath.Join(f.data, "app")),
					"DRUPACK_DRUSH_SCRIPT":    runtime.Canonical(filepath.Join(f.data, "app", "vendor/drush/drush/drush.php")),
					"PATH":                    filepath.Join(f.data, "app", "bin") + string(os.PathListSeparator) + "/usr/bin",
				}
			},
		},
		{
			name:  "Drush's help reads no Site data",
			site:  siteconfig.Site{Port: 8123},
			words: []string{"--data-dir", "missing", "list"},
			want: func(f siteFixture) map[string]string {
				return map[string]string{
					"DRUPACK_RUNTIME_APP_DIR": runtime.Canonical(f.application),
					"DRUPACK_DRUSH_SCRIPT":    runtime.Canonical(filepath.Join(f.application, "vendor/drush/drush/drush.php")),
				}
			},
			absent: []string{"DRUPACK_RUNTIME_DATA_DIR", "DRUSH_OPTIONS_URI"},
		},
	} {
		t.Run(row.name, func(t *testing.T) {
			f := newSite(t, row.site)
			if row.prepare != nil {
				row.prepare(t, f)
			}
			words := row.words
			if !slices.Contains(words, "--data-dir") {
				words = append([]string{"--data-dir", f.data}, words...)
			}
			call, err := drushPlan(f.launch(), parseDrush(t, false, words...), f.cwd)
			if err != nil {
				t.Fatal(err)
			}
			got := environmentOf(call)
			for name, want := range row.want(f) {
				if got[name] != want {
					t.Errorf("%s = %q, want %q", name, got[name], want)
				}
			}
			for _, name := range row.absent {
				if value, set := got[name]; set {
					t.Errorf("%s = %q, want it unset", name, value)
				}
			}
			application := got["DRUPACK_RUNTIME_APP_DIR"]
			if call.Path != "/runtime/frankenphp" || call.Args[0] != "SITE" || call.Args[1] != "php-cli" || call.Args[2] != filepath.Join(application, "drush") {
				t.Errorf("Args = %q, Path = %q", call.Args, call.Path)
			}
			if tail := call.Args[3:]; !slices.Equal(tail, row.words[len(row.words)-len(tail):]) {
				t.Errorf("words = %q, want the tail of %q", tail, row.words)
			}
		})
	}
}

func TestSiteDrushPreparesTheSiteData(t *testing.T) {
	f := newSite(t, siteconfig.Site{Port: 8123, Static: &siteconfig.Static{}})
	put(t, filepath.Join(f.application, "translations", "fr.po"), "bundled")
	put(t, filepath.Join(f.application, "translations", "de.po"), "bundled")
	put(t, filepath.Join(f.data, "files", "translations", "de.po"), "kept")
	if _, err := drushPlan(f.launch(), parseDrush(t, false, "--data-dir", f.data, "status"), f.cwd); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"runtime", "private", "tmp", "config", "logs", "static", "files/translations"} {
		info, err := os.Stat(filepath.Join(f.data, name))
		// Windows reports no owner-only mode.
		if err != nil || !info.IsDir() || (goruntime.GOOS != "windows" && info.Mode().Perm()&0o077 != 0) {
			t.Errorf("%s = %v, %v, want an owner-only directory", name, info, err)
		}
	}
	for name, want := range map[string]string{"fr.po": "bundled", "de.po": "kept"} {
		got, err := os.ReadFile(filepath.Join(f.data, "files", "translations", name))
		if err != nil || string(got) != want {
			t.Errorf("%s = %q, %v, want %q", name, got, err, want)
		}
	}
}

func TestSiteDrushRefusals(t *testing.T) {
	for _, row := range []struct {
		name    string
		site    siteconfig.Site
		prepare func(t *testing.T, f siteFixture)
		words   []string
		err     func(f siteFixture) string
	}{
		{"Site data with no settings", siteconfig.Site{Port: 1}, func(t *testing.T, f siteFixture) { os.Remove(filepath.Join(f.data, "settings.php")) },
			nil, func(f siteFixture) string {
				return "This Site data has no site yet: " + runtime.Canonical(f.data) + ". Start fixture-site once to create one."
			}},
		{"an unreadable record", siteconfig.Site{Port: 1}, func(t *testing.T, f siteFixture) { f.record(t, "not json") },
			nil, func(f siteFixture) string {
				return "Cannot read the recorded listener: " + filepath.Join(f.data, "listener") + ". Remove that file, then start fixture-site again to record it."
			}},
		{"a record with no host", siteconfig.Site{Port: 1}, func(t *testing.T, f siteFixture) { f.record(t, `{"listen":"127.0.0.1:1"}`) },
			nil, func(siteFixture) string { return "Recorded listener has no host" }},
		{"a listener that is no address", siteconfig.Site{Port: 1}, nil, []string{"--listen", "localhost:80"},
			func(siteFixture) string { return "--listen requires IP:PORT, with IPv6 enclosed in brackets" }},
		{"a listener port out of range", siteconfig.Site{Port: 1}, nil, []string{"--listen", "127.0.0.1:70000"},
			func(siteFixture) string { return "Invalid --listen address or port" }},
		{"a static listener on the site's port", siteconfig.Site{Port: 1, Static: &siteconfig.Static{}}, nil, []string{"--listen", "127.0.0.1:5", "--static-listen", "127.0.0.1:5"},
			func(siteFixture) string { return "--static-listen must name a port other than the site's 5" }},
		{"a static listener on a site without one", siteconfig.Site{Port: 1}, nil, []string{"--static-listen", "127.0.0.1:5"},
			func(siteFixture) string {
				return "--static-listen needs a site whose composer.json sets extra.drupack.static"
			}},
		{"a host that is no name", siteconfig.Site{Port: 1}, nil, []string{"--host", "not a host"},
			func(siteFixture) string { return "Invalid --host value" }},
		{"another release's application", siteconfig.Site{Port: 1, Writable: []string{"files"}}, func(t *testing.T, f siteFixture) {
			put(t, filepath.Join(f.data, "app", ".release"), "release-0")
		}, nil, func(f siteFixture) string {
			return "This Site data holds another release's application. Start fixture-site once to lay this release's: " + runtime.Canonical(f.data)
		}},
	} {
		t.Run(row.name, func(t *testing.T) {
			f := newSite(t, row.site)
			if row.prepare != nil {
				row.prepare(t, f)
			}
			words := append([]string{"--data-dir", f.data}, row.words...)
			_, err := drushPlan(f.launch(), parseDrush(t, false, append(words, "status")...), f.cwd)
			if err == nil || err.Error() != row.err(f) {
				t.Errorf("error = %v, want %q", err, row.err(f))
			}
		})
	}
}

// engineFixture is a project holding Drush, a folder to start in and a cache root.
type engineFixture struct {
	project, cwd, root string
}

func newProject(t *testing.T, composer string) engineFixture {
	t.Helper()
	base := realDir(t)
	f := engineFixture{project: filepath.Join(base, "project"), cwd: filepath.Join(base, "project", "web", "core"), root: filepath.Join(base, "cache")}
	put(t, filepath.Join(f.project, "vendor", "autoload.php"), "<?php")
	put(t, filepath.Join(f.project, "composer.json"), composer)
	if err := os.MkdirAll(f.cwd, 0o700); err != nil {
		t.Fatal(err)
	}
	return f
}

func (f engineFixture) launch() Launch {
	return Launch{
		Args:        []string{"drupack", "drush"},
		Parent:      []string{"PATH=/usr/bin", "DRUPACK_RUNTIME_APP_DIR=/stale"},
		Entry:       "/runtime/frankenphp",
		Application: "/cache/app",
		Root:        f.root,
		Name:        "drupack",
		Engine:      true,
	}
}

func TestEngineDrushEnvironment(t *testing.T) {
	f := newProject(t, `{"extra": {"drupack": {"mercure": true}}}`)
	put(t, filepath.Join(f.project, "vendor", "drush", "drush", "drush.php"), "<?php")
	entry := state.FolderEntry(f.root, runtime.Canonical(f.project), false)
	dir := entry.Path()
	put(t, filepath.Join(dir, "ca-bundle.pem"), "roots")
	if err := entry.WriteListener(state.Listener{Listen: "127.0.0.1:3787", Host: "shop.drupack.site", Scheme: "https"}); err != nil {
		t.Fatal(err)
	}
	put(t, filepath.Join(dir, "mercure_publisher_secret"), "pub")
	put(t, filepath.Join(dir, "mercure_subscriber_secret"), "sub")

	call, err := drushPlan(f.launch(), parseDrush(t, true, "status", "--field=db-driver"), f.cwd)
	if err != nil {
		t.Fatal(err)
	}
	got := environmentOf(call)
	for name, want := range map[string]string{
		"DRUPACK_DRUSH_SCRIPT":              runtime.Canonical(filepath.Join(f.project, "vendor/drush/drush/drush.php")),
		"PATH":                              filepath.FromSlash("/cache/app/bin") + string(os.PathListSeparator) + "/usr/bin",
		"DRUPACK_CA_FILE":                   runtime.Canonical(filepath.Join(dir, "ca-bundle.pem")),
		"DRUPACK_MERCURE_URL":               "https://127.0.0.1:3787/.well-known/mercure",
		"DRUPACK_MERCURE_PUBLISHER_SECRET":  "pub",
		"DRUPACK_MERCURE_SUBSCRIBER_SECRET": "sub",
	} {
		if got[name] != want {
			t.Errorf("%s = %q, want %q", name, got[name], want)
		}
	}
	// The engine keeps the reader's directory: the runtime changes into no application.
	if value, set := got["DRUPACK_RUNTIME_APP_DIR"]; set {
		t.Errorf("DRUPACK_RUNTIME_APP_DIR = %q, want it unset", value)
	}
	if want := []string{"drupack", "php-cli", filepath.FromSlash("/cache/app/drush"), "status", "--field=db-driver"}; !slices.Equal(call.Args, want) {
		t.Errorf("Args = %q, want %q", call.Args, want)
	}
}

// The engine's --listen takes a hostname, and drush reads back the record that start wrote.
func TestEngineDrushReadsAHostnameListener(t *testing.T) {
	f := newProject(t, `{"extra": {"drupack": {"mercure": true}}}`)
	put(t, filepath.Join(f.project, "vendor", "drush", "drush", "drush.php"), "<?php")
	entry := state.FolderEntry(f.root, runtime.Canonical(f.project), false)
	put(t, filepath.Join(entry.Path(), "mercure_publisher_secret"), "pub")
	put(t, filepath.Join(entry.Path(), "mercure_subscriber_secret"), "sub")
	if err := entry.WriteListener(state.Listener{Listen: "localhost:8080", Host: "localhost", Scheme: "http"}); err != nil {
		t.Fatal(err)
	}
	call, err := drushPlan(f.launch(), parseDrush(t, true, "status"), f.cwd)
	if err != nil {
		t.Fatal(err)
	}
	if got, want := environmentOf(call)["DRUPACK_MERCURE_URL"], "http://localhost:8080/.well-known/mercure"; got != want {
		t.Errorf("DRUPACK_MERCURE_URL = %q, want %q", got, want)
	}
}

func TestEngineDrushWithoutAServedHub(t *testing.T) {
	f := newProject(t, `{"extra": {"drupack": {"mercure": true}}}`)
	put(t, filepath.Join(f.project, "vendor", "drush", "drush", "drush.php"), "<?php")
	call, err := drushPlan(f.launch(), parseDrush(t, true, "status"), f.project)
	if err != nil {
		t.Fatal(err)
	}
	got := environmentOf(call)
	if value, set := got["DRUPACK_MERCURE_URL"]; set {
		t.Errorf("DRUPACK_MERCURE_URL = %q, want it unset", value)
	}
	// Without a bundle the launcher's public roots stay.
	if want := "/runtime/cacert.pem"; got["DRUPACK_CA_FILE"] != want {
		t.Errorf("DRUPACK_CA_FILE = %q, want %q", got["DRUPACK_CA_FILE"], want)
	}
}

func TestEngineDrushRefusals(t *testing.T) {
	f := newProject(t, `{}`)
	_, err := drushPlan(f.launch(), parseDrush(t, true, "status"), f.cwd)
	script := filepath.Join(f.project, "vendor/drush/drush/drush.php")
	if want := runtime.Canonical(f.project) + " has no Drush: " + runtime.Canonical(script) + " does not exist. Run composer require drush/drush in it."; err == nil || err.Error() != want {
		t.Errorf("error = %v, want %q", err, want)
	}
	bare := realDir(t)
	_, err = drushPlan(f.launch(), parseDrush(t, true, "status"), bare)
	if want := "No vendor/autoload.php in " + runtime.Canonical(bare) + " or any directory above it. Run composer install in the project."; err == nil || err.Error() != want {
		t.Errorf("error = %v, want %q", err, want)
	}
	broken := newProject(t, `{"extra": {"drupack": {"mercure": "yes"}}}`)
	if _, err = drushPlan(broken.launch(), parseDrush(t, true, "status"), broken.cwd); err == nil || !strings.Contains(err.Error(), "extra.drupack.mercure") {
		t.Errorf("error = %v, want the composer.json refusal", err)
	}
}

func TestParseListen(t *testing.T) {
	for _, row := range []struct {
		listen, bind string
		port         int
		err          string
	}{
		{"127.0.0.1:80", "127.0.0.1", 80, ""},
		{"[::1]:65535", "::1", 65535, ""},
		{"0.0.0.0:1", "0.0.0.0", 1, ""},
		{"localhost:80", "", 0, "--listen requires IP:PORT, with IPv6 enclosed in brackets"},
		{"::1:80", "", 0, "--listen requires IP:PORT, with IPv6 enclosed in brackets"},
		{"127.0.0.1:0", "", 0, "Invalid --listen address or port"},
		{"127.0.0.1:65536", "", 0, "Invalid --listen address or port"},
		{"127.0.0.1:99999999999999999999", "", 0, "Invalid --listen address or port"},
		{"999.1.1.1:80", "", 0, "Invalid --listen address or port"},
		{"127.0.0.1:80\n", "", 0, "--listen requires IP:PORT, with IPv6 enclosed in brackets"},
	} {
		bind, port, err := parseListen(row.listen, "--listen")
		message := ""
		if err != nil {
			message = err.Error()
		}
		if bind != row.bind || port != row.port || message != row.err {
			t.Errorf("parseListen(%q) = %q, %d, %q; want %q, %d, %q", row.listen, bind, port, message, row.bind, row.port, row.err)
		}
	}
}

func TestStaticListenerDefaultsToTheNextPort(t *testing.T) {
	bind, port, err := staticListener("", "::1", 8000)
	if bind != "::1" || port != 8001 || err != nil {
		t.Errorf("staticListener = %q, %d, %v", bind, port, err)
	}
}

func TestValidHost(t *testing.T) {
	for host, want := range map[string]bool{
		"localhost": true, "demo.drupack.site": true, "127.0.0.1": true, "::1": true, "a-b.test": true, "example.com.": true,
		"not a host": false, "-a.test": false, "a_b.test": false, "": false, strings.Repeat("a", 64) + ".test": false,
	} {
		if got := validHost(host); got != want {
			t.Errorf("validHost(%q) = %v, want %v", host, got, want)
		}
	}
}

func TestVendorRootFindsTheProjectFromItsSubfolder(t *testing.T) {
	project := realDir(t)
	put(t, filepath.Join(project, "vendor", "autoload.php"), "<?php")
	cwd := filepath.Join(project, "web", "modules", "custom")
	if err := os.MkdirAll(cwd, 0o700); err != nil {
		t.Fatal(err)
	}
	if got, err := vendorRoot(cwd); err != nil || got != project {
		t.Fatalf("vendorRoot() = %q, %v, want %q", got, err, project)
	}
}

func TestVendorRootRefusesALinkedVendor(t *testing.T) {
	elsewhere := realDir(t)
	put(t, filepath.Join(elsewhere, "vendor", "autoload.php"), "<?php")
	for name, target := range map[string]string{
		"vendor":              filepath.Join(elsewhere, "vendor"),
		"vendor/autoload.php": filepath.Join(elsewhere, "vendor", "autoload.php"),
	} {
		t.Run(name, func(t *testing.T) {
			project := realDir(t)
			if name != "vendor" {
				if err := os.Mkdir(filepath.Join(project, "vendor"), 0o700); err != nil {
					t.Fatal(err)
				}
			}
			if err := os.Symlink(target, filepath.Join(project, name)); err != nil {
				t.Skipf("cannot make a link: %v", err)
			}
			if _, err := vendorRoot(project); err == nil || !strings.Contains(err.Error(), "is a link") {
				t.Fatalf("vendorRoot() = %v, want a refusal naming the link", err)
			}
		})
	}
}

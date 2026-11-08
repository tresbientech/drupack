package main

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/state"
)

const staticComposer = `{"extra": {"drupack": {"static": {}}}}`

// staticProject is a project whose composer.json sets a static listener and whose
// composer.lock holds the module that listener needs.
func staticProject(t *testing.T) engineFixture {
	t.Helper()
	f := newProject(t, staticComposer)
	put(t, filepath.Join(f.project, "composer.lock"), `{"packages": [{"name": "drupal/core", "version": "11.4.7"}, {"name": "drupal/tome", "version": "1.16.0"}]}`)
	put(t, filepath.Join(f.project, "vendor", "drush", "drush", "drush.php"), "<?php")
	return f
}

func TestEngineExportReadsTheRecordedStaticAddress(t *testing.T) {
	f := staticProject(t)
	entry := state.FolderEntry(f.root, f.project, false)
	if err := os.MkdirAll(entry.Path(), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := entry.WriteListener(state.Listener{Listen: "127.0.0.1:3787", Host: "shop.drupack.site", Scheme: "https", StaticListen: "127.0.0.1:3788"}); err != nil {
		t.Fatal(err)
	}
	e := environ{entries: []string{"PATH=/usr/bin"}}
	x, err := engineExport(f.launch(), parseLine(t, true, "export", "--", "--extra"), f.cwd, e)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{"drupack", "php-cli", "/cache/app/drush", "tome:static", "--yes", "--uri=https://shop.drupack.site:3788/", "--", "--extra"}
	if !slices.Equal(x.run.Args, want) {
		t.Errorf("export Args = %q, want %q", x.run.Args, want)
	}
	if got := environmentOf(x.run)["DRUPACK_DRUSH_SCRIPT"]; got != filepath.Join(f.project, "vendor/drush/drush/drush.php") {
		t.Errorf("DRUPACK_DRUSH_SCRIPT = %q", got)
	}
	if want := []string{"drupack", "php-cli", "/cache/app/drush", "php:eval", tomeProbe}; !slices.Equal(x.probe.Args, want) {
		t.Errorf("probe Args = %q, want %q", x.probe.Args, want)
	}
	// A reader who names the URL gets none added, and the record is not read.
	x, err = engineExport(f.launch(), parseLine(t, true, "export", "-l", "http://other:1/"), f.cwd, e)
	if err != nil {
		t.Fatal(err)
	}
	if want := []string{"drupack", "php-cli", "/cache/app/drush", "tome:static", "--yes", "-l", "http://other:1/"}; !slices.Equal(x.run.Args, want) {
		t.Errorf("Args with a URL = %q, want %q", x.run.Args, want)
	}
}

func TestEngineExportRefusals(t *testing.T) {
	e := environ{entries: []string{"PATH=/usr/bin"}}
	none := newProject(t, `{}`)
	_, err := engineExport(none.launch(), parseLine(t, true, "export"), none.cwd, e)
	if want := none.project + "/composer.json sets no extra.drupack.static, so there is nothing to export."; err == nil || err.Error() != want {
		t.Errorf("no static: %v, want %q", err, want)
	}
	f := staticProject(t)
	want := "Cannot read a recorded static URL for " + f.project + ". Start it once, or pass --uri URL."
	_, err = engineExport(f.launch(), parseLine(t, true, "export"), f.cwd, e)
	if err == nil || err.Error() != want {
		t.Errorf("never started: %v, want %q", err, want)
	}
	// A folder an earlier release served holds the old files alone, which read as never started.
	entry := state.FolderEntry(f.root, f.project, false)
	put(t, filepath.Join(entry.Path(), "static_url"), "http://127.0.0.1:3788/")
	if _, err = engineExport(f.launch(), parseLine(t, true, "export"), f.cwd, e); err == nil || err.Error() != want {
		t.Errorf("old files: %v, want %q", err, want)
	}
	if err := entry.WriteListener(state.Listener{Listen: "127.0.0.1:3787", Host: "localhost", Scheme: "http"}); err != nil {
		t.Fatal(err)
	}
	if _, err = engineExport(f.launch(), parseLine(t, true, "export"), f.cwd, e); err == nil || err.Error() != want {
		t.Errorf("a record with no static listener: %v, want %q", err, want)
	}
	noDrush := staticProject(t)
	if err := os.Remove(filepath.Join(noDrush.project, "vendor", "drush", "drush", "drush.php")); err != nil {
		t.Fatal(err)
	}
	_, err = engineExport(noDrush.launch(), parseLine(t, true, "export", "--uri=http://x/"), noDrush.cwd, e)
	if err == nil || !strings.Contains(err.Error(), "has no Drush") {
		t.Errorf("no Drush: %v", err)
	}
}

func TestNamesURI(t *testing.T) {
	for words, want := range map[string]bool{
		"":                  false,
		"--uri":             true,
		"--uri=http://x/":   true,
		"-lhttp://x/":       true,
		"--yes --uri=a":     true,
		"-- --uri=a":        false,
		"--uriish":          false,
		"--destination=/tm": false,
	} {
		if got := namesURI(strings.Fields(words)); got != want {
			t.Errorf("namesURI(%q) = %v, want %v", words, got, want)
		}
	}
}

func TestEngineCore(t *testing.T) {
	f := newProject(t, `{}`)
	l := f.launch()
	l.Args = []string{"drupack", "dr"}
	e := environ{entries: []string{"PATH=/usr/bin"}}
	_, err := engineCore(l, parseLine(t, true, "dr", "list"), f.cwd, e)
	script := filepath.Join(f.project, "vendor/bin/dr")
	if want := f.project + " has no Drupal core command line: " + script + " does not exist. Drupal 11.4 and later ship it."; err == nil || err.Error() != want {
		t.Errorf("error = %v, want %q", err, want)
	}
	put(t, script, "<?php")
	call, err := engineCore(l, parseLine(t, true, "dr", "list", "--raw"), f.cwd, e)
	if err != nil {
		t.Fatal(err)
	}
	if want := []string{"drupack", "php-cli", script, "list", "--raw"}; !slices.Equal(call.Args, want) {
		t.Errorf("Args = %q, want %q", call.Args, want)
	}
	if got := environmentOf(call)["PATH"]; got != "/cache/app/bin:/usr/bin" {
		t.Errorf("PATH = %q", got)
	}
}

func TestSiteExportAddressesTheRecordedStaticListener(t *testing.T) {
	f := newSite(t, siteconfig.Site{Port: 8123, Static: &siteconfig.Static{}})
	f.record(t, `{"listen":"127.0.0.1:9000","host":"shop.drupack.site","scheme":"https","static-listen":"127.0.0.1:9100"}`)
	c := parseLine(t, false, "export", "--data-dir", f.data, "--", "--extra")
	e := environ{entries: []string{"PATH=/usr/bin"}}
	x, err := siteExport(f.launch(), c, f.cwd, e)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{"SITE", "php-cli", filepath.Join(f.application, "drush"), "tome:static", "--yes", "--uri=https://shop.drupack.site:9100/", "--", "--extra"}
	if !slices.Equal(x.run.Args, want) {
		t.Errorf("export Args = %q, want %q", x.run.Args, want)
	}
	if want := []string{"SITE", "php-cli", filepath.Join(f.application, "drush"), "php:eval", tomeProbe}; !slices.Equal(x.probe.Args, want) {
		t.Errorf("probe Args = %q, want %q", x.probe.Args, want)
	}
	if got := environmentOf(x.run)["DRUPACK_RUNTIME_STATIC_ROOT"]; got != filepath.Join(f.data, "static") {
		t.Errorf("DRUPACK_RUNTIME_STATIC_ROOT = %q", got)
	}
	// A reader who names the URL gets none added.
	x, err = siteExport(f.launch(), parseLine(t, false, "export", "--data-dir", f.data, "-l", "http://other:1/"), f.cwd, e)
	if err != nil {
		t.Fatal(err)
	}
	if want := []string{"SITE", "php-cli", filepath.Join(f.application, "drush"), "tome:static", "--yes", "-l", "http://other:1/"}; !slices.Equal(x.run.Args, want) {
		t.Errorf("Args with a URL = %q, want %q", x.run.Args, want)
	}
}

func TestSiteExportDefaultsToTheNextPortOnUnrecordedSiteData(t *testing.T) {
	f := newSite(t, siteconfig.Site{Port: 8123, Static: &siteconfig.Static{}})
	x, err := siteExport(f.launch(), parseLine(t, false, "export", "--data-dir", f.data), f.cwd, environ{entries: []string{"PATH=/usr/bin"}})
	if err != nil {
		t.Fatal(err)
	}
	if got := x.run.Args[5]; got != "--uri=http://localhost:8124/" {
		t.Errorf("uri = %q", got)
	}
}

func TestSiteExportRefusals(t *testing.T) {
	e := environ{entries: []string{"PATH=/usr/bin"}}
	plain := newSite(t, siteconfig.Site{Port: 1})
	_, err := siteExport(plain.launch(), parseLine(t, false, "export", "--data-dir", plain.data), plain.cwd, e)
	if want := "export needs a site whose composer.json sets extra.drupack.static"; err == nil || err.Error() != want {
		t.Errorf("no static: %v, want %q", err, want)
	}
	f := newSite(t, siteconfig.Site{Port: 1, Static: &siteconfig.Static{}})
	_, err = siteExport(f.launch(), parseLine(t, false, "export", "--data-dir", f.data, "--database", "oracle"), f.cwd, e)
	if want := "--database requires sqlite, mysql, or pgsql"; err == nil || err.Error() != want {
		t.Errorf("database: %v, want %q", err, want)
	}
	os.Remove(filepath.Join(f.data, "settings.php"))
	_, err = siteExport(f.launch(), parseLine(t, false, "export", "--data-dir", f.data), f.cwd, e)
	if want := "This Site data has no site yet: " + f.data + ". Start fixture-site once to create one."; err == nil || err.Error() != want {
		t.Errorf("no site: %v, want %q", err, want)
	}
}

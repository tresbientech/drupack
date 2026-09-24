package siteconfig_test

import (
	"encoding/json"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

const minimal = "name: mysite\nrecipe: recipes/my_site\nsite_name: My Site\n"

func TestParseFillsDefaults(t *testing.T) {
	site, err := siteconfig.Parse([]byte(minimal))
	if err != nil {
		t.Fatal(err)
	}
	want := siteconfig.Site{
		Name: "mysite", Port: 7225, Recipe: "recipes/my_site", SiteName: "My Site",
		Languages: []string{}, SmokePaths: []string{"/"}, Extensions: []string{},
		Platforms: []string{"linux-amd64"}, Libc: "both",
	}
	if !reflect.DeepEqual(site, want) {
		t.Fatalf("Parse(minimal) = %+v; want %+v", site, want)
	}
}

func TestParseKeepsEveryField(t *testing.T) {
	content := minimal + "port: 7300\nlanguages: [fr, zh-hans]\nsmoke_paths: [/, /about]\n" +
		"platforms: [linux-amd64, linux-arm64]\nlibc: musl\nextensions: [xmlwriter, pdo_sqlsrv]\n"
	site, err := siteconfig.Parse([]byte(content))
	if err != nil {
		t.Fatal(err)
	}
	if site.Port != 7300 || !reflect.DeepEqual(site.Languages, []string{"fr", "zh-hans"}) ||
		!reflect.DeepEqual(site.SmokePaths, []string{"/", "/about"}) ||
		!reflect.DeepEqual(site.Platforms, []string{"linux-amd64", "linux-arm64"}) || site.Libc != "musl" ||
		!reflect.DeepEqual(site.Extensions, []string{"xmlwriter", "pdo_sqlsrv"}) {
		t.Fatalf("Parse kept %+v", site)
	}
}

func TestParseAcceptsASiteWithoutARecipe(t *testing.T) {
	site, err := siteconfig.Parse([]byte("name: mysite\nsite_name: My Site\n"))
	if err != nil {
		t.Fatal(err)
	}
	if site.Recipe != "" {
		t.Fatalf("recipe = %q; want none", site.Recipe)
	}
}

func TestParseNamesTheRejectedField(t *testing.T) {
	cases := map[string]struct {
		content string
		field   string
	}{
		"missing name":        {"recipe: r\nsite_name: S\n", "name:"},
		"uppercase name":      {"name: MySite\nrecipe: r\nsite_name: S\n", "name:"},
		"name with slash":     {"name: my/site\nrecipe: r\nsite_name: S\n", "name:"},
		"port too high":       {minimal + "port: 70000\n", "port:"},
		"negative port":       {minimal + "port: -1\n", "port:"},
		"absolute recipe":     {"name: mysite\nrecipe: /etc\nsite_name: S\n", "recipe:"},
		"escaping recipe":     {"name: mysite\nrecipe: recipes/../..\nsite_name: S\n", "recipe:"},
		"recipe with space":   {"name: mysite\nrecipe: 'my recipe'\nsite_name: S\n", "recipe:"},
		"missing site name":   {"name: mysite\nrecipe: r\n", "site_name:"},
		"blank site name":     {"name: mysite\nrecipe: r\nsite_name: '  '\n", "site_name:"},
		"two-line site name":  {"name: mysite\nrecipe: r\nsite_name: \"a\\nb\"\n", "site_name:"},
		"english":             {minimal + "languages: [en]\n", "languages:"},
		"language path":       {minimal + "languages: ['../fr']\n", "languages:"},
		"relative smoke path": {minimal + "smoke_paths: [about]\n", "smoke_paths:"},
		"escaping smoke path": {minimal + "smoke_paths: [/a/../../b]\n", "smoke_paths:"},
		"extension path":      {minimal + "extensions: [../gmp]\n", "extensions:"},
		"extension list":      {minimal + "extensions: ['gmp,intl']\n", "extensions:"},
		"uppercase extension": {minimal + "extensions: [GMP]\n", "extensions:"},
		"absolute settings":   {minimal + "settings: /etc/acme.php\n", "settings:"},
		"escaping settings":   {minimal + "settings: ../acme.php\n", "settings:"},
		"no platform":         {minimal + "platforms: []\n", "platforms:"},
		"macOS platform":      {minimal + "platforms: [linux-amd64, macos-arm64]\n", "platforms:"},
		"comma platforms":     {minimal + "platforms: linux-amd64,linux-arm64\n", "platforms"},
		"unknown libc":        {minimal + "libc: gnu\n", "libc:"},
		"unknown field":       {minimal + "colour: blue\n", "colour"},
	}
	for label, c := range cases {
		_, err := siteconfig.Parse([]byte(c.content))
		if err == nil || !strings.Contains(err.Error(), c.field) {
			t.Errorf("%s: Parse error = %v; want one naming %q", label, err, c.field)
		}
	}
}

// site writes a site directory holding drupack.yml and composer.json.
func site(t *testing.T, drupack, composer string) string {
	t.Helper()
	directory := t.TempDir()
	for name, content := range map[string]string{siteconfig.FileName: drupack, "composer.json": composer} {
		if err := os.WriteFile(filepath.Join(directory, name), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return directory
}

func scaffold(webRoot string) string {
	return `{"extra": {"drupal-scaffold": {"locations": {"web-root": "` + webRoot + `"}}}}`
}

func TestReadTakesTheDocrootFromComposer(t *testing.T) {
	cases := map[string]struct{ composer, want string }{
		"acquia layout": {scaffold("docroot/"), "docroot"},
		"no slash":      {scaffold("web"), "web"},
		"nested":        {scaffold("app/public/"), "app/public"},
		"leading dot":   {scaffold("./docroot/"), "docroot"},
	}
	for label, c := range cases {
		read, err := siteconfig.Read(site(t, minimal, c.composer))
		if err != nil {
			t.Errorf("%s: %v", label, err)
			continue
		}
		if read.Docroot != c.want {
			t.Errorf("%s: docroot = %q; want %q", label, read.Docroot, c.want)
		}
	}
}

func TestReadRefusesADocrootOutsideTheSite(t *testing.T) {
	composers := []string{`{"name": "acme/site"}`}
	for _, webRoot := range []string{"/var/www/html", "../shared/web", "web/../..", "."} {
		composers = append(composers, scaffold(webRoot))
	}
	for _, composer := range composers {
		_, err := siteconfig.Read(site(t, minimal, composer))
		if err == nil || !strings.Contains(err.Error(), "web-root") {
			t.Errorf("%s: Read error = %v; want one naming web-root", composer, err)
		}
	}
}

func TestReadTakesASettingsFileTheSiteHolds(t *testing.T) {
	directory := site(t, minimal+"settings: acme.settings.php\n", scaffold("web"))
	if _, err := siteconfig.Read(directory); err == nil || !strings.Contains(err.Error(), "settings:") {
		t.Fatalf("Read error = %v; want one naming the absent settings file", err)
	}
	if err := os.WriteFile(filepath.Join(directory, "acme.settings.php"), []byte("<?php"), 0o644); err != nil {
		t.Fatal(err)
	}
	read, err := siteconfig.Read(directory)
	if err != nil {
		t.Fatal(err)
	}
	if read.Settings != "acme.settings.php" {
		t.Fatalf("settings = %q; want acme.settings.php", read.Settings)
	}
}

func TestWriteProducesTheSiteJSONShape(t *testing.T) {
	read, err := siteconfig.Read(site(t, minimal+"languages: [fr]\n", scaffold("docroot/")))
	if err != nil {
		t.Fatal(err)
	}
	directory := t.TempDir()
	if err := siteconfig.Write(read, directory); err != nil {
		t.Fatal(err)
	}
	content, err := os.ReadFile(filepath.Join(directory, siteconfig.OutputName))
	if err != nil {
		t.Fatal(err)
	}
	var written map[string]any
	if err := json.Unmarshal(content, &written); err != nil {
		t.Fatal(err)
	}
	want := map[string]any{
		"name": "mysite", "port": float64(7225), "recipe": "recipes/my_site", "site_name": "My Site",
		"languages": []any{"fr"}, "smoke_paths": []any{"/"}, "extensions": []any{}, "docroot": "docroot", "settings": "",
		"platforms": []any{"linux-amd64"}, "libc": "both",
	}
	if !reflect.DeepEqual(written, want) {
		t.Fatalf("site.json = %v; want %v", written, want)
	}
}

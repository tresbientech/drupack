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
		Platforms: []string{"linux-amd64"}, Libc: "both", Writable: []string{},
	}
	if !reflect.DeepEqual(site, want) {
		t.Fatalf("Parse(minimal) = %+v; want %+v", site, want)
	}
}

func TestParseKeepsEveryField(t *testing.T) {
	content := minimal + "port: 7300\nlanguages: [fr, zh-hans]\nsmoke_paths: [/, /about]\n" +
		"platforms: [linux-amd64, linux-arm64]\nlibc: musl\nextensions: [xmlwriter, pdo_sqlsrv]\n" +
		"writable: [web/themes/custom, recipes]\n"
	site, err := siteconfig.Parse([]byte(content))
	if err != nil {
		t.Fatal(err)
	}
	if site.Port != 7300 || !reflect.DeepEqual(site.Languages, []string{"fr", "zh-hans"}) ||
		!reflect.DeepEqual(site.SmokePaths, []string{"/", "/about"}) ||
		!reflect.DeepEqual(site.Platforms, []string{"linux-amd64", "linux-arm64"}) || site.Libc != "musl" ||
		!reflect.DeepEqual(site.Extensions, []string{"xmlwriter", "pdo_sqlsrv"}) ||
		!reflect.DeepEqual(site.Writable, []string{"web/themes/custom", "recipes"}) {
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
		"missing name":         {"recipe: r\nsite_name: S\n", "name:"},
		"uppercase name":       {"name: MySite\nrecipe: r\nsite_name: S\n", "name:"},
		"name with slash":      {"name: my/site\nrecipe: r\nsite_name: S\n", "name:"},
		"port too high":        {minimal + "port: 70000\n", "port:"},
		"negative port":        {minimal + "port: -1\n", "port:"},
		"absolute recipe":      {"name: mysite\nrecipe: /etc\nsite_name: S\n", "recipe:"},
		"escaping recipe":      {"name: mysite\nrecipe: recipes/../..\nsite_name: S\n", "recipe:"},
		"recipe with space":    {"name: mysite\nrecipe: 'my recipe'\nsite_name: S\n", "recipe:"},
		"missing site name":    {"name: mysite\nrecipe: r\n", "site_name:"},
		"blank site name":      {"name: mysite\nrecipe: r\nsite_name: '  '\n", "site_name:"},
		"two-line site name":   {"name: mysite\nrecipe: r\nsite_name: \"a\\nb\"\n", "site_name:"},
		"english":              {minimal + "languages: [en]\n", "languages:"},
		"language path":        {minimal + "languages: ['../fr']\n", "languages:"},
		"relative smoke path":  {minimal + "smoke_paths: [about]\n", "smoke_paths:"},
		"escaping smoke path":  {minimal + "smoke_paths: [/a/../../b]\n", "smoke_paths:"},
		"extension path":       {minimal + "extensions: [../gmp]\n", "extensions:"},
		"extension list":       {minimal + "extensions: ['gmp,intl']\n", "extensions:"},
		"uppercase extension":  {minimal + "extensions: [GMP]\n", "extensions:"},
		"absolute settings":    {minimal + "settings: /etc/acme.php\n", "settings:"},
		"escaping settings":    {minimal + "settings: ../acme.php\n", "settings:"},
		"no platform":          {minimal + "platforms: []\n", "platforms:"},
		"unknown platform":     {minimal + "platforms: [linux-amd64, freebsd-amd64]\n", "platforms:"},
		"extension on macOS":   {minimal + "platforms: [macos-arm64]\nextensions: [gmp]\n", "gmp"},
		"extension on Windows": {minimal + "platforms: [linux-amd64, windows-amd64]\nextensions: [gmp, intl]\n", "windows-amd64"},
		"comma platforms":      {minimal + "platforms: linux-amd64,linux-arm64\n", "platforms"},
		"unknown libc":         {minimal + "libc: gnu\n", "libc:"},
		"unknown field":        {minimal + "colour: blue\n", "colour"},
		"absolute writable":    {minimal + "writable: [/var/www]\n", "writable:"},
		"escaping writable":    {minimal + "writable: [web/../..]\n", "writable:"},
		"unclean writable":     {minimal + "writable: [web/themes/]\n", "writable:"},
		"dot writable":         {minimal + "writable: [web/./themes]\n", "writable:"},
		"backslash writable":   {minimal + "writable: ['web\\\\themes']\n", "writable:"},
		"duplicate writable":   {minimal + "writable: [recipes, recipes]\n", "writable:"},
		"nested writable":      {minimal + "writable: [web/themes, web/themes/custom]\n", "writable:"},
	}
	for label, c := range cases {
		_, err := siteconfig.Parse([]byte(c.content))
		if err == nil || !strings.Contains(err.Error(), c.field) {
			t.Errorf("%s: Parse error = %v; want one naming %q", label, err, c.field)
		}
	}
}

func TestParseReadsEachNodeForm(t *testing.T) {
	cases := map[string]siteconfig.Node{
		"":                  "",
		"node: false\n":     "",
		"node: true\n":      siteconfig.NodeLTS,
		"node: 24\n":        "24",
		"node: 24.21.0\n":   "24.21.0",
		"node: '24.21.0'\n": "24.21.0",
	}
	for field, want := range cases {
		site, err := siteconfig.Parse([]byte(minimal + field))
		if err != nil {
			t.Errorf("%q: %v", field, err)
			continue
		}
		if site.Node != want {
			t.Errorf("%q: node = %q; want %q", field, site.Node, want)
		}
	}
}

func TestParseRefusesAMalformedNodeWithTheAcceptedForms(t *testing.T) {
	for _, field := range []string{
		"node: 24.21\n", "node: '24'\n", "node: 0\n", "node: -24\n", "node: v24.21.0\n", "node: lts\n",
		"node: 24.x\n", "node: '>=24'\n", "node: [24]\n", "node: {version: 24}\n",
	} {
		_, err := siteconfig.Parse([]byte(minimal + field))
		if err == nil || !strings.Contains(err.Error(), "node:") ||
			!strings.Contains(err.Error(), "true, false, a major version such as 24, or a version such as 24.21.0") {
			t.Errorf("%q: Parse error = %v; want one naming node and its forms", field, err)
		}
	}
}

// site writes a site directory holding drupack.yml and composer.json.
func site(t *testing.T, drupack, composer string) string {
	t.Helper()
	directory := t.TempDir()
	for name, content := range map[string]string{siteconfig.FileName: drupack, "composer.json": composer, "composer.lock": locked} {
		if err := os.WriteFile(filepath.Join(directory, name), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	return directory
}

// locked is a composer.lock naming the two releases Read takes.
const locked = `{"packages": [{"name": "drupal/core", "version": "11.4.7"}, {"name": "drush/drush", "version": "13.8.0"}]}`

func scaffold(webRoot string) string {
	return `{"extra": {"drupal-scaffold": {"locations": {"web-root": "` + webRoot + `"}}}}`
}

func TestReadTakesTheDocrootFromComposer(t *testing.T) {
	cases := map[string]struct{ composer, want string }{
		"docroot layout": {scaffold("docroot/"), "docroot"},
		"no slash":       {scaffold("web"), "web"},
		"nested":         {scaffold("app/public/"), "app/public"},
		"leading dot":    {scaffold("./docroot/"), "docroot"},
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

func TestReadTakesDrupalAndDrushFromTheLock(t *testing.T) {
	read, err := siteconfig.Read(site(t, minimal, scaffold("web")))
	if err != nil {
		t.Fatal(err)
	}
	if read.Drupal != "11.4.7" || read.Drush != "13.8.0" {
		t.Fatalf("drupal, drush = %q, %q; want 11.4.7, 13.8.0", read.Drupal, read.Drush)
	}
}

func TestReadRefusesALockWithoutDrupalOrDrush(t *testing.T) {
	for missing, lock := range map[string]string{
		"drupal/core": `{"packages": [{"name": "drush/drush", "version": "13.8.0"}]}`,
		"drush/drush": `{"packages": [{"name": "drupal/core", "version": "11.4.7"}]}`,
	} {
		directory := site(t, minimal, scaffold("web"))
		if err := os.WriteFile(filepath.Join(directory, "composer.lock"), []byte(lock), 0o644); err != nil {
			t.Fatal(err)
		}
		if _, err := siteconfig.Read(directory); err == nil || !strings.Contains(err.Error(), missing) {
			t.Errorf("Read error = %v; want one naming %s", err, missing)
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
		"platforms": []any{"linux-amd64"}, "libc": "both", "writable": []any{}, "mercure": false, "drupal": "11.4.7", "drush": "13.8.0",
	}
	if !reflect.DeepEqual(written, want) {
		t.Fatalf("site.json = %v; want %v", written, want)
	}
}

func TestSiteJSONCarriesNodeInTheFormItWasGiven(t *testing.T) {
	for field, want := range map[string]any{
		"node: true\n": true, "node: 24\n": float64(24), "node: 24.21.0\n": "24.21.0",
	} {
		site, err := siteconfig.Parse([]byte(minimal + field))
		if err != nil {
			t.Fatal(err)
		}
		content, err := json.Marshal(site)
		if err != nil {
			t.Fatal(err)
		}
		var written map[string]any
		if err := json.Unmarshal(content, &written); err != nil {
			t.Fatal(err)
		}
		if written["node"] != want {
			t.Errorf("%q: site.json node = %v; want %v", field, written["node"], want)
		}
		var read siteconfig.Site
		if err := json.Unmarshal(content, &read); err != nil || read.Node != site.Node {
			t.Errorf("%q: site.json reads back as %q, %v; want %q", field, read.Node, err, site.Node)
		}
	}
}

func TestParseRefusesMercureWithItsNewKey(t *testing.T) {
	for _, field := range []string{"mercure: true\n", "mercure: false\n"} {
		_, err := siteconfig.Parse([]byte(minimal + field))
		if err == nil || !strings.Contains(err.Error(), "mercure moved to composer.json extra.drupack.mercure") {
			t.Errorf("%q: Parse error = %v; want one naming composer.json extra.drupack.mercure", field, err)
		}
	}
}

// drupackExtra is a composer.json with the web root web and extra.drupack set to drupack.
func drupackExtra(drupack string) string {
	return `{"extra": {"drupal-scaffold": {"locations": {"web-root": "web"}}, "drupack": ` + drupack + `}}`
}

// application/tests/composer-extra.json holds the extra.drupack cases engine/serve.php
// shares with Read. Each row names the settings Read takes, or the field its refusal names.
func TestReadAppliesTheSharedComposerExtraCases(t *testing.T) {
	content, err := os.ReadFile(filepath.Join("..", "..", "..", "application", "tests", "composer-extra.json"))
	if err != nil {
		t.Fatal(err)
	}
	var rows []struct {
		Drupack   json.RawMessage
		Mercure   bool
		Subdomain *string
		Refuses   string
	}
	if err := json.Unmarshal(content, &rows); err != nil {
		t.Fatal(err)
	}
	for _, row := range rows {
		read, err := siteconfig.Read(site(t, minimal, drupackExtra(string(row.Drupack))))
		if row.Refuses != "" {
			if err == nil || !strings.Contains(err.Error(), row.Refuses) {
				t.Errorf("%s: Read error = %v; want one naming %s", row.Drupack, err, row.Refuses)
			}
			continue
		}
		if err != nil {
			t.Errorf("%s: %v", row.Drupack, err)
			continue
		}
		want := ""
		if row.Subdomain != nil {
			want = *row.Subdomain
		}
		if read.Mercure != row.Mercure || read.Subdomain != want {
			t.Errorf("%s: Mercure, Subdomain = %v, %q; want %v, %q", row.Drupack, read.Mercure, read.Subdomain, row.Mercure, want)
		}
	}
}

func TestParseAcceptsEveryNativeTarget(t *testing.T) {
	site, err := siteconfig.Parse([]byte(minimal + "platforms: [linux-amd64, macos-amd64, macos-arm64, windows-amd64]\n"))
	if err != nil {
		t.Fatal(err)
	}
	if want := []string{"linux-amd64", "macos-amd64", "macos-arm64", "windows-amd64"}; !reflect.DeepEqual(site.Platforms, want) {
		t.Fatalf("Platforms = %v; want %v", site.Platforms, want)
	}
}

func TestParseKeepsExtensionsOnLinuxTargets(t *testing.T) {
	if _, err := siteconfig.Parse([]byte(minimal + "platforms: [linux-amd64, linux-arm64]\nextensions: [gmp]\n")); err != nil {
		t.Fatal(err)
	}
}

func TestTheExtensionRefusalNamesTheExtensionsAndTheTargets(t *testing.T) {
	_, err := siteconfig.Parse([]byte(minimal + "platforms: [linux-amd64, macos-arm64, windows-amd64]\nextensions: [gmp, intl]\n"))
	if err == nil {
		t.Fatal("Parse accepted added extensions with native targets")
	}
	for _, want := range []string{"macos-arm64, windows-amd64", "gmp, intl"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("error %q does not name %q", err, want)
		}
	}
	if strings.Contains(err.Error(), "linux-amd64") {
		t.Errorf("error %q names a Linux target", err)
	}
}

func TestParseRefusesASubdomainInDrupackYml(t *testing.T) {
	_, err := siteconfig.Parse([]byte(minimal + "subdomain: demo\n"))
	if err == nil || !strings.Contains(err.Error(), "composer.json extra.drupack.subdomain") {
		t.Fatalf("Parse error = %v; want one naming composer.json extra.drupack.subdomain", err)
	}
}

// application/tests/site.json is the reference file the PHP tests read the Site
// contract from. A change to what Write produces fails here until that file holds it.
// The fixture sets subdomain and node, which Write leaves out when a site sets neither.
func TestWriteMatchesThePHPReferenceFile(t *testing.T) {
	read, err := siteconfig.Read(site(t, "name: fixture-site\nsite_name: Fixture Site\nnode: 24\n", drupackExtra(`{"subdomain": "fixture"}`)))
	if err != nil {
		t.Fatal(err)
	}
	directory := t.TempDir()
	if err := siteconfig.Write(read, directory); err != nil {
		t.Fatal(err)
	}
	got, err := os.ReadFile(filepath.Join(directory, siteconfig.OutputName))
	if err != nil {
		t.Fatal(err)
	}
	reference := filepath.Join("..", "..", "..", "application", "tests", "site.json")
	want, err := os.ReadFile(reference)
	if err != nil {
		t.Fatal(err)
	}
	if string(got) != string(want) {
		t.Fatalf("%s differs from what Write produces. Replace it with:\n%s", reference, got)
	}
}

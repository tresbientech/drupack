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
	}
	if !reflect.DeepEqual(site, want) {
		t.Fatalf("Parse(minimal) = %+v; want %+v", site, want)
	}
}

func TestParseKeepsEveryField(t *testing.T) {
	content := minimal + "port: 7300\nlanguages: [fr, zh-hans]\nsmoke_paths: [/, /about]\n"
	site, err := siteconfig.Parse([]byte(content))
	if err != nil {
		t.Fatal(err)
	}
	if site.Port != 7300 || !reflect.DeepEqual(site.Languages, []string{"fr", "zh-hans"}) ||
		!reflect.DeepEqual(site.SmokePaths, []string{"/", "/about"}) {
		t.Fatalf("Parse kept %+v", site)
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
		"missing recipe":      {"name: mysite\nsite_name: S\n", "recipe:"},
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
		"extension additions": {minimal + "extensions: [gmp]\n", "extensions:"},
		"unknown field":       {minimal + "colour: blue\n", "colour"},
	}
	for label, c := range cases {
		_, err := siteconfig.Parse([]byte(c.content))
		if err == nil || !strings.Contains(err.Error(), c.field) {
			t.Errorf("%s: Parse error = %v; want one naming %q", label, err, c.field)
		}
	}
}

func TestWriteProducesTheSiteJSONShape(t *testing.T) {
	site, err := siteconfig.Parse([]byte(minimal + "languages: [fr]\n"))
	if err != nil {
		t.Fatal(err)
	}
	directory := t.TempDir()
	if err := siteconfig.Write(site, directory); err != nil {
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
		"languages": []any{"fr"}, "smoke_paths": []any{"/"}, "extensions": []any{},
	}
	if !reflect.DeepEqual(written, want) {
		t.Fatalf("site.json = %v; want %v", written, want)
	}
}

package main

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/fsguard"
)

const drupalComposer = `{"require": {"drupal/core-recommended": "^11"}}`

func TestEngineComposerRunsThePharOnTheRuntimeInTheProject(t *testing.T) {
	f := newProject(t, drupalComposer)
	l := f.launch()
	call, err := engineComposer(Facts{Launch: l}, parseLine(t, true, "composer", "install", "--no-dev"), f.project)
	if err != nil {
		t.Fatal(err)
	}
	if want := []string{"drupack", "php-cli", filepath.Join(l.Application, "composer.phar"), "install", "--no-dev"}; !slices.Equal(call.Args, want) {
		t.Errorf("Args = %q, want %q", call.Args, want)
	}
	env := environmentOf(call)
	if got, want := env["PHP_BINARY"], fsguard.Canonical(phpBinary(l)); got != want {
		t.Errorf("PHP_BINARY = %q, want %q", got, want)
	}
	if got, want := env["PATH"], binDir(l.Application)+string(os.PathListSeparator); !strings.HasPrefix(got, want) {
		t.Errorf("PATH = %q, want it to start with %q", got, want)
	}
}

func TestEngineComposerRefusesAnotherProjectAndItsOwnUpdate(t *testing.T) {
	f := newProject(t, drupalComposer)
	cases := []struct {
		words []string
		want  string
	}{
		{[]string{"global", "require", "acme/tool"}, "refuses global"},
		{[]string{"GLOBAL", "require", "acme/tool"}, "refuses global"},
		{[]string{"-n", "glob", "show"}, "refuses global"},
		{[]string{"self-update"}, "carries its own Composer. Run ./drupack self-update"},
		{[]string{"selfupdate", "--rollback"}, "carries its own Composer. Run ./drupack self-update"},
		{[]string{"-d", "/elsewhere", "install"}, "and refuses -d."},
		{[]string{"install", "-d/elsewhere"}, "and refuses -d/elsewhere."},
		{[]string{"-nd", "/elsewhere", "install"}, "and refuses -nd."},
		{[]string{"--working-dir", "/elsewhere", "install"}, "and refuses --working-dir."},
		{[]string{"--working-dir=/elsewhere", "install"}, "and refuses --working-dir=/elsewhere."},
	}
	for _, c := range cases {
		_, err := engineComposer(Facts{Launch: f.launch()}, parseLine(t, true, append([]string{"composer"}, c.words...)...), f.project)
		if err == nil || !strings.Contains(err.Error(), c.want) {
			t.Errorf("composer %q: error = %v, want one holding %q", c.words, err, c.want)
		}
	}
	l := f.launch()
	l.Parent = append(l.Parent, "COMPOSER=other.json")
	if _, err := engineComposer(Facts{Launch: l}, parseLine(t, true, "composer", "install"), f.project); err == nil || !strings.Contains(err.Error(), "refuses a set COMPOSER") {
		t.Errorf("COMPOSER=other.json: error = %v, want the COMPOSER refusal", err)
	}
	l.Parent = append(f.launch().Parent, "COMPOSER= ")
	if _, err := engineComposer(Facts{Launch: l}, parseLine(t, true, "composer", "install"), f.project); err != nil {
		t.Errorf("a blank COMPOSER, which Composer ignores: %v", err)
	}
}

func TestEngineComposerPassesWordsAfterTheOptionsEnd(t *testing.T) {
	f := newProject(t, drupalComposer)
	for _, words := range [][]string{{"run-script", "task", "--", "-d", "global"}, {"search", "drupal"}, {"-n", "show"}, {"config", "--global", "github-oauth.github.com"}, {"c", "drupal/cms"}} {
		if _, err := engineComposer(Facts{Launch: f.launch()}, parseLine(t, true, append([]string{"composer"}, words...)...), f.project); err != nil {
			t.Errorf("composer %q: %v", words, err)
		}
	}
}

func TestEngineComposerRefusesAProjectWithoutDrupal(t *testing.T) {
	f := newProject(t, `{"require": {"laravel/framework": "^12"}}`)
	_, err := engineComposer(Facts{Launch: f.launch()}, parseLine(t, true, "composer", "install"), f.project)
	if err == nil || !strings.Contains(err.Error(), "holds no Drupal project") {
		t.Errorf("error = %v, want the Drupal refusal", err)
	}
	if _, err := engineComposer(Facts{Launch: f.launch()}, parseLine(t, true, "composer", "install"), f.cwd); err == nil || !strings.Contains(err.Error(), "holds no composer.json") {
		t.Errorf("from a subdirectory: error = %v, want the composer.json refusal", err)
	}
	// Drupal core's own package, which requires the scaffold.
	put(t, filepath.Join(f.cwd, "composer.json"), `{"name": "drupal/core", "type": "drupal-core", "require": {"drupal/core-composer-scaffold": "*"}, "extra": {"drupal-scaffold": {}}}`)
	if _, err := engineComposer(Facts{Launch: f.launch()}, parseLine(t, true, "composer", "require", "drupal/token"), f.cwd); err == nil || !strings.Contains(err.Error(), "holds no Drupal project") {
		t.Errorf("from web/core: error = %v, want the Drupal refusal", err)
	}
}

func TestASiteExecutableHasNoComposerCommand(t *testing.T) {
	_, err := parse(Launch{Args: []string{"SITE", "composer", "install"}, Name: "fixture-site", Invoked: "./fixture-site"})
	if err == nil || !strings.HasPrefix(err.Error(), "Unknown command: composer") {
		t.Errorf("error = %v, want Unknown command: composer", err)
	}
}

func TestCreateProjectTargetReadsThePackageAndVersion(t *testing.T) {
	cases := []struct {
		words         []string
		name, version string
	}{
		{[]string{"create-project", "drupal/cms", "site"}, "drupal/cms", ""},
		{[]string{"Create-P", "drupal/cms"}, "drupal/cms", ""},
		{[]string{"create-project", "drupal/cms:^2", "site"}, "drupal/cms", "^2"},
		{[]string{"create-project", "drupal/cms=2.2.9"}, "drupal/cms", "2.2.9"},
		{[]string{"create-project", "drupal/cms", "site", "2.2.9"}, "drupal/cms", "2.2.9"},
		{[]string{"create-project", "drupal/cms:^1", "site", "^2"}, "drupal/cms", "^2"},
		{[]string{"-n", "create-project", "-s", "dev", "drupal/cms"}, "drupal/cms", ""},
		{[]string{"create-project", "-ns", "dev", "--stability=rc", "drupal/cms"}, "drupal/cms", ""},
		{[]string{"create-project", "--require", "drupal/token", "--audit-format", "json", "--prefer-install", "dist", "--ignore-platform-req", "ext-gd", "drupal/cms"}, "drupal/cms", ""},
		{[]string{"create-project", "--no-install", "--", "drupal/cms", "site"}, "drupal/cms", ""},
	}
	for _, c := range cases {
		name, version, err := createProjectTarget(Launch{Invoked: "./drupack"}, c.words)
		if err != nil || name != c.name || version != c.version {
			t.Errorf("%q: (%q, %q, %v), want (%q, %q)", c.words, name, version, err, c.name, c.version)
		}
	}
}

func TestCreateProjectTargetRefusesAnotherRepositoryAndNoPackage(t *testing.T) {
	cases := []struct {
		words []string
		want  string
	}{
		{[]string{"create-project", "--repository", "https://example.com", "acme/site"}, "refuses --repository, which reads another repository"},
		{[]string{"create-project", "--repository={\"type\":\"path\"}", "acme/site"}, "refuses --repository={"},
		{[]string{"create-project", "--repository-url=https://example.com", "acme/site"}, "refuses --repository-url=https://example.com,"},
		{[]string{"create-project", "--add-repository", "acme/site"}, "refuses --add-repository,"},
		{[]string{"create-project"}, "create-project needs a package, such as drupal/cms."},
		{[]string{"create-project", "-s", "dev"}, "create-project needs a package"},
	}
	for _, c := range cases {
		_, _, err := createProjectTarget(Launch{Invoked: "./drupack"}, c.words)
		if err == nil || !strings.Contains(err.Error(), c.want) {
			t.Errorf("%q: error = %v, want one holding %q", c.words, err, c.want)
		}
	}
}

func TestPackageFromShowReadsTheTypeAndRequirements(t *testing.T) {
	kind, require, err := packageFromShow([]byte(`{"name": "drupal/cms", "type": "project", "requires": {"drupal/core-recommended": "^11.5"}}`))
	if err != nil || kind != "project" || require["drupal/core-recommended"] != "^11.5" {
		t.Errorf("drupal/cms: (%q, %v, %v)", kind, require, err)
	}
	if kind, require, err := packageFromShow([]byte(`{"name": "acme/bare", "type": "project"}`)); err != nil || kind != "project" || require != nil {
		t.Errorf("no requires: (%q, %v, %v), want project and none", kind, require, err)
	}
	for report, want := range map[string]string{
		``:                                    "the report is no JSON object",
		`["project"]`:                         "the report is no JSON object",
		`{"type": 1}`:                         "the report is no JSON object",
		`{"type": "project", "requires": []}`: "the report is no JSON object",
		`{"name": "acme/site"}`:               "the report names no type",
		`{"type": "project"} {}`:              "the report holds more than one JSON value",
	} {
		if _, _, err := packageFromShow([]byte(report)); err == nil || !strings.Contains(err.Error(), want) {
			t.Errorf("%q: error = %v, want one holding %q", report, err, want)
		}
	}
}

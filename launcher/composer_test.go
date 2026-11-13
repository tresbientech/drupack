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
		{[]string{"create-project", "drupal/cms", "site"}, "refuses create-project"},
		{[]string{"Create-P", "drupal/cms", "site"}, "refuses create-project"},
		{[]string{"self-update"}, "carries its own Composer. Run drupack self-update"},
		{[]string{"selfupdate", "--rollback"}, "carries its own Composer. Run drupack self-update"},
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
	for _, words := range [][]string{{"run-script", "task", "--", "-d", "global"}, {"search", "drupal"}, {"-n", "show"}, {"config", "--global", "github-oauth.github.com"}} {
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
	_, err := parse(Launch{Args: []string{"SITE", "composer", "install"}, Name: "fixture-site"})
	if err == nil || !strings.HasPrefix(err.Error(), "Unknown command: composer") {
		t.Errorf("error = %v, want Unknown command: composer", err)
	}
}

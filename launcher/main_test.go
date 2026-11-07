package main

import (
	"bytes"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func folder(t *testing.T, composer string) string {
	t.Helper()
	project := t.TempDir()
	if err := os.WriteFile(filepath.Join(project, "composer.json"), []byte(composer), 0o644); err != nil {
		t.Fatal(err)
	}
	return project
}

func TestComposerSettingsPrintsTheFolderSettings(t *testing.T) {
	project := folder(t, `{"extra": {"drupal-scaffold": {"locations": {"web-root": "./"}}, "drupack": {"mercure": true, "subdomain": "shop"}}}`)
	var out bytes.Buffer
	if err := composerSettings([]string{project}, &out); err != nil {
		t.Fatal(err)
	}
	if want := `{"docroot":".","mercure":true,"subdomain":"shop"}` + "\n"; out.String() != want {
		t.Errorf("output = %q; want %q", out.String(), want)
	}
}

// lockedFolder is folder with a composer.lock naming the given packages.
func lockedFolder(t *testing.T, composer string, packages ...string) string {
	t.Helper()
	project := folder(t, composer)
	locked := make([]string, 0, len(packages))
	for _, name := range packages {
		locked = append(locked, `{"name": "`+name+`", "version": "1.0.0"}`)
	}
	lock := `{"packages": [` + strings.Join(locked, ", ") + `]}`
	if err := os.WriteFile(filepath.Join(project, "composer.lock"), []byte(lock), 0o644); err != nil {
		t.Fatal(err)
	}
	return project
}

const staticComposer = `{"extra": {"drupal-scaffold": {"locations": {"web-root": "./"}}, "drupack": {"static": {"dynamic": ["/search*"]}}}}`

func TestComposerSettingsPrintsTheStaticSettings(t *testing.T) {
	project := lockedFolder(t, staticComposer, "drupal/core", "drupal/tome")
	var out bytes.Buffer
	if err := composerSettings([]string{project}, &out); err != nil {
		t.Fatal(err)
	}
	if want := `{"docroot":".","mercure":false,"static":{"dynamic":["/search*"]}}` + "\n"; out.String() != want {
		t.Errorf("output = %q; want %q", out.String(), want)
	}
}

func TestComposerSettingsRefusesALockWithoutTome(t *testing.T) {
	project := lockedFolder(t, staticComposer, "drupal/core")
	var out bytes.Buffer
	err := composerSettings([]string{project}, &out)
	if err == nil || !strings.Contains(err.Error(), "composer.lock") || !strings.Contains(err.Error(), "drupal/tome") {
		t.Errorf("error = %v; want one naming composer.lock and drupal/tome", err)
	}
	if out.Len() != 0 {
		t.Errorf("output = %q; want none", out.String())
	}
}

func TestComposerSettingsRefusesWhatTheFolderModeRefuses(t *testing.T) {
	project := folder(t, `{"extra": {"drupal-scaffold": {"locations": {"web-root": "C:/web"}}}}`)
	var out bytes.Buffer
	err := composerSettings([]string{project}, &out)
	if err == nil || !strings.Contains(err.Error(), "extra.drupal-scaffold.locations.web-root must name a directory inside the project") {
		t.Errorf("error = %v; want the web-root refusal", err)
	}
	if out.Len() != 0 {
		t.Errorf("output = %q; want none", out.String())
	}
}

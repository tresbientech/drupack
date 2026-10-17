package main

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"testing"
)

const defaultSettings = "<?php\n// default.settings.php\n"

type entry struct {
	name, body string
	kind       byte
	link       string
}

// project is the smallest archive new accepts: a default.settings.php and a file beside it.
var project = []entry{
	{name: "web/", kind: tar.TypeDir},
	{name: "web/sites/default/default.settings.php", body: defaultSettings, kind: tar.TypeReg},
	{name: "composer.json", body: "{}", kind: tar.TypeReg},
}

func archiveOf(t *testing.T, entries []entry) []byte {
	t.Helper()
	var buffer bytes.Buffer
	zipped := gzip.NewWriter(&buffer)
	writer := tar.NewWriter(zipped)
	for _, e := range entries {
		header := &tar.Header{Name: e.name, Typeflag: e.kind, Linkname: e.link, Mode: 0o644, Size: int64(len(e.body))}
		if e.kind == tar.TypeDir {
			header.Mode = 0o755
		}
		if err := writer.WriteHeader(header); err != nil {
			t.Fatal(err)
		}
		if _, err := io.WriteString(writer, e.body); err != nil {
			t.Fatal(err)
		}
	}
	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}
	if err := zipped.Close(); err != nil {
		t.Fatal(err)
	}
	return buffer.Bytes()
}

// archiveRelease serves body as the Drupal CMS archive, listed with its own size and SHA-256.
func archiveRelease(t *testing.T, body []byte) string {
	t.Helper()
	digest := sha256.Sum256(body)
	latest := release{Version: "2026.10.1", CMS: "2.2.5", Core: "11.4.2", Assets: []asset{{
		Name: archiveName, Target: "any", Asset: "drupal-cms-2026.10.1.tar.gz",
		SHA256: hex.EncodeToString(digest[:]), Size: int64(len(body)),
	}}}
	return publish(t, latest, body...).URL + "/release.json"
}

func names(t *testing.T, directory string) []string {
	t.Helper()
	entries, err := os.ReadDir(directory)
	if err != nil {
		t.Fatal(err)
	}
	var found []string
	for _, e := range entries {
		found = append(found, e.Name())
	}
	return found
}

func TestNewRefusesBeforeAnyRequest(t *testing.T) {
	parent := t.TempDir()
	full := filepath.Join(parent, "full")
	if err := os.MkdirAll(full, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(full, "keep.txt"), []byte("mine"), 0o644); err != nil {
		t.Fatal(err)
	}
	for _, c := range []struct {
		name      string
		arguments []string
		message   string
	}{
		{"no directory", nil, "new takes one directory"},
		{"two directories", []string{"a", "b"}, "new takes one directory"},
		{"an option", []string{"--force"}, "new takes one directory"},
		{"a directory holding files", []string{full}, full + " holds files"},
	} {
		t.Run(c.name, func(t *testing.T) {
			t.Chdir(parent)
			_, err := create(c.arguments, "http://127.0.0.1:1/release.json", io.Discard)
			if err == nil || !strings.Contains(err.Error(), c.message) {
				t.Fatalf("create = %v, want %q", err, c.message)
			}
			if got := names(t, parent); !slices.Equal(got, []string{"full"}) {
				t.Fatalf("parent holds %v", got)
			}
			if got := names(t, full); !slices.Equal(got, []string{"keep.txt"}) {
				t.Fatalf("full holds %v", got)
			}
		})
	}
}

func TestNewLaysTheArchiveAndWritesItsSettings(t *testing.T) {
	parent := t.TempDir()
	directory := filepath.Join(parent, "site")
	var out bytes.Buffer
	created, err := create([]string{directory}, archiveRelease(t, archiveOf(t, project)), &out)
	if err != nil {
		t.Fatal(err)
	}
	if created != directory {
		t.Fatalf("create returned %s", created)
	}
	if got := names(t, parent); !slices.Equal(got, []string{"site"}) {
		t.Fatalf("parent holds %v, want site alone", got)
	}
	if composer, err := os.ReadFile(filepath.Join(directory, "composer.json")); err != nil || string(composer) != "{}" {
		t.Fatalf("composer.json = %q, %v", composer, err)
	}
	settings, err := os.ReadFile(filepath.Join(directory, "web", "sites", "default", "settings.php"))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(string(settings), defaultSettings) {
		t.Fatalf("settings.php does not start with default.settings.php: %q", settings)
	}
	for _, line := range []string{
		`'driver' => 'sqlite',`,
		`'database' => dirname(DRUPAL_ROOT) . '/db/site.sqlite',`,
		`$settings['config_sync_directory'] = '../config';`,
		`$settings['package_manager_allow_direct_write'] = TRUE;`,
		`$settings['testing_package_manager'] = TRUE;`,
		`$settings['skip_permissions_hardening'] = TRUE;`,
		`$settings['trusted_host_patterns'] = ['^127\.0\.0\.1$', '^localhost$'];`,
		`putenv('IS_DDEV_PROJECT=1');`,
	} {
		if !strings.Contains(string(settings), line) {
			t.Errorf("settings.php lacks %s", line)
		}
	}
	if !regexp.MustCompile(`\$settings\['hash_salt'\] = '[A-Za-z0-9_-]{74}';`).Match(settings) {
		t.Errorf("settings.php holds no 55-byte hash salt:\n%s", settings)
	}
	for _, path := range []string{"db", filepath.Join("web", "sites", "default", "files")} {
		if info, err := os.Stat(filepath.Join(directory, path)); err != nil || !info.IsDir() {
			t.Errorf("%s is no directory: %v", path, err)
		}
	}
	for _, line := range []string{"Downloading Drupal CMS 2.2.5, Drupal 11.4.2", "Created " + directory + "."} {
		if !strings.Contains(out.String(), line) {
			t.Errorf("output %q lacks %q", out.String(), line)
		}
	}
}

func TestNewWritesADifferentSaltEachTime(t *testing.T) {
	parent := t.TempDir()
	url := archiveRelease(t, archiveOf(t, project))
	salt := regexp.MustCompile(`\$settings\['hash_salt'\] = '([^']+)';`)
	var salts []string
	for _, name := range []string{"one", "two"} {
		directory, err := create([]string{filepath.Join(parent, name)}, url, io.Discard)
		if err != nil {
			t.Fatal(err)
		}
		settings, err := os.ReadFile(filepath.Join(directory, "web", "sites", "default", "settings.php"))
		if err != nil {
			t.Fatal(err)
		}
		salts = append(salts, salt.FindStringSubmatch(string(settings))[1])
	}
	if salts[0] == salts[1] {
		t.Fatalf("both projects hold the salt %s", salts[0])
	}
}

func TestNewFillsAnEmptyDirectory(t *testing.T) {
	directory := filepath.Join(t.TempDir(), "empty")
	if err := os.Mkdir(directory, 0o755); err != nil {
		t.Fatal(err)
	}
	if _, err := create([]string{directory}, archiveRelease(t, archiveOf(t, project)), io.Discard); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(directory, "composer.json")); err != nil {
		t.Fatal(err)
	}
}

func TestNewLeavesNothingWhenTheDownloadDiffers(t *testing.T) {
	parent := t.TempDir()
	body := archiveOf(t, project)
	digest := sha256.Sum256(body)
	latest := release{Version: "2026.10.1", Assets: []asset{{
		Name: archiveName, Target: "any", SHA256: hex.EncodeToString(digest[:]), Size: int64(len(body)),
	}}}
	tampered := append(slices.Clone(body[:len(body)-1]), body[len(body)-1]^0xff)
	url := publish(t, latest, tampered...).URL + "/release.json"
	_, err := create([]string{filepath.Join(parent, "site")}, url, io.Discard)
	if err == nil || !strings.Contains(err.Error(), "does not match the size and SHA-256") {
		t.Fatalf("create = %v", err)
	}
	if got := names(t, parent); len(got) != 0 {
		t.Fatalf("parent holds %v", got)
	}
}

func TestNewNamesAReleaseWithoutTheArchive(t *testing.T) {
	parent := t.TempDir()
	latest := release{Version: "2026.10.1", Assets: []asset{{Name: "drupack", Target: "linux-amd64"}}}
	_, err := create([]string{filepath.Join(parent, "site")}, publish(t, latest).URL+"/release.json", io.Discard)
	if err == nil || !strings.Contains(err.Error(), "drupal-cms 2026.10.1 has no any build") {
		t.Fatalf("create = %v", err)
	}
	if got := names(t, parent); len(got) != 0 {
		t.Fatalf("parent holds %v", got)
	}
}

func TestNewRefusesAnEntryLeavingTheProject(t *testing.T) {
	for _, c := range []struct {
		name  string
		entry entry
	}{
		{"a parent segment", entry{name: "../escaped.php", body: "<?php", kind: tar.TypeReg}},
		{"an absolute path", entry{name: "/escaped.php", body: "<?php", kind: tar.TypeReg}},
		{"a symbolic link", entry{name: "web/escaped", kind: tar.TypeSymlink, link: "../../.."}},
		{"a hard link", entry{name: "web/escaped", kind: tar.TypeLink, link: "composer.json"}},
	} {
		t.Run(c.name, func(t *testing.T) {
			root := t.TempDir()
			parent := filepath.Join(root, "parent")
			if err := os.Mkdir(parent, 0o755); err != nil {
				t.Fatal(err)
			}
			url := archiveRelease(t, archiveOf(t, append(slices.Clone(project), c.entry)))
			if _, err := create([]string{filepath.Join(parent, "site")}, url, io.Discard); err == nil {
				t.Fatal("create accepted the entry")
			}
			if got := names(t, parent); len(got) != 0 {
				t.Fatalf("parent holds %v", got)
			}
			if got := names(t, root); !slices.Equal(got, []string{"parent"}) {
				t.Fatalf("the directory above holds %v", got)
			}
		})
	}
}

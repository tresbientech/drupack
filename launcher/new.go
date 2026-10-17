package main

import (
	"archive/tar"
	"compress/gzip"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
)

// archiveURL answers with the release.json of the newest Drupal CMS archive.
const archiveURL = "https://github.com/tresbientech/drupal-cms-archive/releases/latest/download/release.json"

// archiveName names the archive's asset in that release.json, which serves every
// platform under the target "any".
const archiveName = "drupal-cms"

// projectSettings follows the archive's default.settings.php. The database sits
// beside the docroot, which the server and Drush resolve from different working
// directories, so its path starts at DRUPAL_ROOT.
const projectSettings = `
$databases['default']['default'] = [
  'driver' => 'sqlite',
  'database' => dirname(DRUPAL_ROOT) . '/db/site.sqlite',
  'namespace' => 'Drupal\\sqlite\\Driver\\Database\\sqlite',
  'autoload' => 'core/modules/sqlite/src/Driver/Database/sqlite/',
];
$settings['hash_salt'] = '%s';
$settings['config_sync_directory'] = '../config';
$settings['package_manager_allow_direct_write'] = TRUE;
$settings['testing_package_manager'] = TRUE;
$settings['skip_permissions_hardening'] = TRUE;
$settings['trusted_host_patterns'] = ['^127\.0\.0\.1$', '^localhost$'];
// The Drupal CMS installer warns about skip_permissions_hardening unless it runs under DDEV.
putenv('IS_DDEV_PROJECT=1');
`

// create runs the new word: it lays the newest Drupal CMS archive into the
// directory arguments names, writes its settings, and returns that directory.
func create(arguments []string, url string, out io.Writer) (string, error) {
	if len(arguments) != 1 || arguments[0] == "" || arguments[0][0] == '-' {
		return "", fmt.Errorf("new takes one directory: %s new DIR", siteName)
	}
	directory, err := filepath.Abs(arguments[0])
	if err != nil {
		return "", err
	}
	entries, err := os.ReadDir(directory)
	if err == nil && len(entries) > 0 {
		return "", fmt.Errorf("%s holds files. new creates a project in a missing or empty directory.", directory)
	}
	if err != nil && !errors.Is(err, fs.ErrNotExist) {
		return "", fmt.Errorf("new creates a project in a missing or empty directory: %w", err)
	}
	// The project is laid beside its directory and renamed into place last, so a
	// failure leaves nothing at the name the reader gave.
	staging, err := os.MkdirTemp(filepath.Dir(directory), "."+filepath.Base(directory)+".new-*")
	if err != nil {
		return "", fmt.Errorf("cannot write beside %s: %w", directory, err)
	}
	defer os.RemoveAll(staging)
	client, err := httpClient()
	if err != nil {
		return "", err
	}
	latest, err := fetchRelease(client, url)
	if err != nil {
		return "", err
	}
	chosen, err := pick(latest, archiveName, "any")
	if err != nil {
		return "", err
	}
	fmt.Fprintf(out, "Downloading Drupal CMS %s, Drupal %s: %d MB.\n", latest.CMS, latest.Core, chosen.Size>>20)
	archive, err := os.CreateTemp(filepath.Dir(directory), "."+filepath.Base(directory)+".archive-*")
	if err != nil {
		return "", err
	}
	defer os.Remove(archive.Name())
	defer archive.Close()
	if err := download(client, chosen, archive); err != nil {
		return "", err
	}
	project := filepath.Join(staging, "project")
	if err := extract(archive.Name(), project); err != nil {
		return "", err
	}
	if err := writeSettings(project); err != nil {
		return "", err
	}
	// Windows renames onto no existing directory, empty or not.
	if err := os.Remove(directory); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return "", err
	}
	if err := os.Rename(project, directory); err != nil {
		return "", err
	}
	fmt.Fprintf(out, "Created %s.\n", directory)
	return directory, nil
}

// extract unpacks the gzipped tar at file into directory. The archive is remote
// data, so an entry naming a path outside directory, or any link, is refused.
func extract(file, directory string) error {
	handle, err := os.Open(file)
	if err != nil {
		return err
	}
	defer handle.Close()
	unzipped, err := gzip.NewReader(handle)
	if err != nil {
		return fmt.Errorf("the Drupal CMS archive is no gzip file: %w", err)
	}
	entries := tar.NewReader(unzipped)
	for {
		header, err := entries.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return fmt.Errorf("cannot read the Drupal CMS archive: %w", err)
		}
		if !filepath.IsLocal(header.Name) {
			return fmt.Errorf("the Drupal CMS archive names a path outside its project: %s", header.Name)
		}
		path := filepath.Join(directory, header.Name)
		switch header.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(path, 0o755); err != nil {
				return err
			}
		case tar.TypeReg:
			if err := extractFile(entries, path, header.FileInfo().Mode().Perm()); err != nil {
				return err
			}
		default:
			return fmt.Errorf("the Drupal CMS archive holds %s, which is no file or directory", header.Name)
		}
	}
}

func extractFile(entries io.Reader, path string, mode fs.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	file, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_EXCL, mode)
	if err != nil {
		return err
	}
	if _, err := io.Copy(file, entries); err != nil {
		file.Close()
		return err
	}
	return file.Close()
}

// writeSettings writes the project's settings.php from its default.settings.php,
// with a hash salt of its own, and the directories those settings name.
func writeSettings(project string) error {
	site := filepath.Join(project, "web", "sites", "default")
	defaults, err := os.ReadFile(filepath.Join(site, "default.settings.php"))
	if err != nil {
		return err
	}
	// Drupal's installer draws 55 bytes for a salt.
	salt := make([]byte, 55)
	rand.Read(salt)
	settings := append(defaults, fmt.Sprintf(projectSettings, base64.RawURLEncoding.EncodeToString(salt))...)
	if err := os.WriteFile(filepath.Join(site, "settings.php"), settings, 0o644); err != nil {
		return err
	}
	for _, directory := range []string{filepath.Join(project, "db"), filepath.Join(site, "files")} {
		if err := os.MkdirAll(directory, 0o755); err != nil {
			return err
		}
	}
	return nil
}

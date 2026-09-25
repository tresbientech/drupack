package runtime

import (
	"archive/tar"
	"bytes"
	"os"
	"path/filepath"
	"testing"

	"github.com/klauspost/compress/zstd"
)

// appArchive compresses a tar holding files, each path mapped to its contents.
func appArchive(t *testing.T, files map[string]string) []byte {
	t.Helper()
	archive := &bytes.Buffer{}
	writer := tar.NewWriter(archive)
	for name, contents := range files {
		header := &tar.Header{Name: name, Mode: 0644, Size: int64(len(contents)), Typeflag: tar.TypeReg}
		if err := writer.WriteHeader(header); err != nil {
			t.Fatal(err)
		}
		if _, err := writer.Write([]byte(contents)); err != nil {
			t.Fatal(err)
		}
	}
	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}
	payload := &bytes.Buffer{}
	compressor, err := zstd.NewWriter(payload)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := compressor.Write(archive.Bytes()); err != nil {
		t.Fatal(err)
	}
	if err := compressor.Close(); err != nil {
		t.Fatal(err)
	}
	return payload.Bytes()
}

// siteData returns a Site data directory holding the runtime directory a start creates.
func siteData(t *testing.T) string {
	t.Helper()
	data := t.TempDir()
	if err := os.Mkdir(filepath.Join(data, "runtime"), 0700); err != nil {
		t.Fatal(err)
	}
	return data
}

func read(t *testing.T, path string) string {
	t.Helper()
	contents, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return string(contents)
}

func TestLaySiteAppLaysTheReleaseAndItsWritableDirectories(t *testing.T) {
	data := siteData(t)
	payload := appArchive(t, map[string]string{"web/index.php": "index", "recipes/site/recipe.yml": "recipe"})
	notice := &bytes.Buffer{}
	if err := LaySiteApp(data, "release-one", payload, []string{"web/themes/custom", "recipes"}, notice); err != nil {
		t.Fatal(err)
	}
	app := SiteApp(data)
	if got := read(t, filepath.Join(app, "web", "index.php")); got != "index" {
		t.Errorf("web/index.php holds %q", got)
	}
	if info, err := os.Stat(filepath.Join(app, "web", "themes", "custom")); err != nil || !info.IsDir() {
		t.Errorf("the absent writable directory was not created: %v", err)
	}
	if got := read(t, filepath.Join(app, "recipes", "site", "recipe.yml")); got != "recipe" {
		t.Errorf("a shipped writable directory lost its contents: %q", got)
	}
	if !SiteAppCurrent(data, "release-one") {
		t.Error("the laid application does not name its release")
	}
	if !bytes.Contains(notice.Bytes(), []byte("Laying the application")) {
		t.Errorf("the lay announced nothing: %q", notice.String())
	}
}

func TestLaySiteAppKeepsAnApplicationOfTheSameRelease(t *testing.T) {
	data := siteData(t)
	payload := appArchive(t, map[string]string{"web/index.php": "index"})
	if err := LaySiteApp(data, "release-one", payload, []string{"web/themes/custom"}, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	written := filepath.Join(SiteApp(data), "web", "themes", "custom", "probe", "probe.info.yml")
	if err := os.MkdirAll(filepath.Dir(written), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(written, []byte("probe"), 0600); err != nil {
		t.Fatal(err)
	}
	notice := &bytes.Buffer{}
	if err := LaySiteApp(data, "release-one", payload, []string{"web/themes/custom"}, notice); err != nil {
		t.Fatal(err)
	}
	if notice.Len() != 0 {
		t.Errorf("a second start of the same release reported %q", notice.String())
	}
	if got := read(t, written); got != "probe" {
		t.Errorf("the site's write holds %q", got)
	}
}

func TestLaySiteAppRelaysAfterAnInterruptedLay(t *testing.T) {
	data := siteData(t)
	// An interrupted lay leaves a staging directory and no application.
	staging := filepath.Join(data, stagingPrefix+siteAppName)
	if err := os.MkdirAll(filepath.Join(staging, "web"), 0700); err != nil {
		t.Fatal(err)
	}
	if SiteAppCurrent(data, "release-one") {
		t.Fatal("a Site data directory with no application reads as current")
	}
	payload := appArchive(t, map[string]string{"web/index.php": "index"})
	if err := LaySiteApp(data, "release-one", payload, nil, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(staging); !os.IsNotExist(err) {
		t.Errorf("the staging directory survived the lay: %v", err)
	}
	if !SiteAppCurrent(data, "release-one") {
		t.Error("the relaid application does not name its release")
	}
}

func TestLaySiteAppRefusesAWritableDirectoryOutsideTheApplication(t *testing.T) {
	data := siteData(t)
	payload := appArchive(t, map[string]string{"web/index.php": "index"})
	for _, directory := range []string{"../outside", "/etc"} {
		if err := LaySiteApp(data, "release-one", payload, []string{directory}, &bytes.Buffer{}); err == nil {
			t.Errorf("LaySiteApp accepted %q", directory)
		}
	}
}

func TestLayArgumentsReadsBothForms(t *testing.T) {
	data, writable, check, err := LayArguments([]string{"/data", "web/themes/custom", "recipes"})
	if err != nil || data != "/data" || check || len(writable) != 2 {
		t.Errorf("lay form read as %q %q %v %v", data, writable, check, err)
	}
	data, _, check, err = LayArguments([]string{"--check", "/data"})
	if err != nil || data != "/data" || !check {
		t.Errorf("check form read as %q %v %v", data, check, err)
	}
	for _, arguments := range [][]string{{}, {"--check"}, {"--dry-run", "/data"}} {
		if _, _, _, err := LayArguments(arguments); err == nil {
			t.Errorf("LayArguments accepted %q", arguments)
		}
	}
}

// written writes contents at the path relative under the site's application in data.
func written(t *testing.T, data, relative, contents string) string {
	t.Helper()
	path := filepath.Join(SiteApp(data), filepath.FromSlash(relative))
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(contents), 0600); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestLaySiteAppCarriesTheSitesWritesToTheNextRelease(t *testing.T) {
	data := siteData(t)
	writable := []string{"web/themes/custom", "recipes"}
	if err := LaySiteApp(data, "release-one", appArchive(t, map[string]string{"web/index.php": "one"}), writable, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	theme := written(t, data, "web/themes/custom/probe/probe.info.yml", "probe")
	recipe := written(t, data, "recipes/probe_site/recipe.yml", "site recipe")
	outside := written(t, data, "web/sites/stray.txt", "stray")

	if err := LaySiteApp(data, "release-two", appArchive(t, map[string]string{"web/index.php": "two"}), writable, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if got := read(t, filepath.Join(SiteApp(data), "web", "index.php")); got != "two" {
		t.Errorf("the upgrade kept the old release's index.php: %q", got)
	}
	if got := read(t, theme); got != "probe" {
		t.Errorf("the written theme holds %q", got)
	}
	if got := read(t, recipe); got != "site recipe" {
		t.Errorf("the written recipe holds %q", got)
	}
	if _, err := os.Stat(outside); !os.IsNotExist(err) {
		t.Errorf("a write outside the writable directories survived the upgrade: %v", err)
	}
	if _, err := os.Stat(filepath.Join(data, previousAppName)); !os.IsNotExist(err) {
		t.Errorf("the old application survived the upgrade: %v", err)
	}
}

func TestLaySiteAppLetsTheReleaseReplaceAnEntryItShips(t *testing.T) {
	data := siteData(t)
	writable := []string{"recipes"}
	if err := LaySiteApp(data, "release-one", appArchive(t, map[string]string{"recipes/site/recipe.yml": "one"}), writable, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	written(t, data, "recipes/site/recipe.yml", "edited by the site")
	written(t, data, "recipes/site/extra.yml", "added by the site")
	if err := LaySiteApp(data, "release-two", appArchive(t, map[string]string{"recipes/site/recipe.yml": "two"}), writable, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if got := read(t, filepath.Join(SiteApp(data), "recipes", "site", "recipe.yml")); got != "two" {
		t.Errorf("the shipped recipe holds %q", got)
	}
	if _, err := os.Stat(filepath.Join(SiteApp(data), "recipes", "site", "extra.yml")); !os.IsNotExist(err) {
		t.Errorf("a file inside a shipped entry carried over: %v", err)
	}
}

func TestLaySiteAppResumesAnUpgradeInterruptedBeforeItsSwap(t *testing.T) {
	data := siteData(t)
	writable := []string{"web/themes/custom"}
	if err := LaySiteApp(data, "release-one", appArchive(t, map[string]string{"web/index.php": "one"}), writable, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	written(t, data, "web/themes/custom/probe/probe.info.yml", "probe")
	// The upgrade moved the old application aside, then stopped with a half-written staging directory.
	if err := os.Rename(SiteApp(data), filepath.Join(data, previousAppName)); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(data, stagingPrefix+siteAppName, "web"), 0700); err != nil {
		t.Fatal(err)
	}

	if err := LaySiteApp(data, "release-two", appArchive(t, map[string]string{"web/index.php": "two"}), writable, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if got := read(t, filepath.Join(SiteApp(data), "web", "themes", "custom", "probe", "probe.info.yml")); got != "probe" {
		t.Errorf("the written theme holds %q", got)
	}
	if !SiteAppCurrent(data, "release-two") {
		t.Error("the resumed upgrade does not name its release")
	}
}

func TestLaySiteAppRemovesTheOldApplicationAnInterruptedSwapLeft(t *testing.T) {
	data := siteData(t)
	payload := appArchive(t, map[string]string{"web/index.php": "two"})
	if err := LaySiteApp(data, "release-two", payload, nil, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	previous := filepath.Join(data, previousAppName)
	if err := os.MkdirAll(previous, 0700); err != nil {
		t.Fatal(err)
	}
	if err := LaySiteApp(data, "release-two", payload, nil, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(previous); !os.IsNotExist(err) {
		t.Errorf("the old application survived: %v", err)
	}
}

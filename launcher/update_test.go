package main

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	goruntime "runtime"
	"strings"
	"testing"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// packedAs sets the payload values pack stamps, for one test.
func packedAs(t *testing.T, name, release string, isEngine bool) {
	t.Helper()
	savedName, savedVersion, savedEngine := siteName, siteVersion, engine
	siteName, siteVersion, engine = name, release, isEngine
	t.Cleanup(func() { siteName, siteVersion, engine = savedName, savedVersion, savedEngine })
	// Root creates a missing directory owner-only, as it requires.
	t.Setenv("DRUPACK_CACHE_DIR", filepath.Join(t.TempDir(), "cache"))
}

// publish serves latest as release.json, and body at /asset, which each asset's URL names.
func publish(t *testing.T, latest release, body ...byte) *httptest.Server {
	t.Helper()
	var server *httptest.Server
	server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/release.json":
			for i := range latest.Assets {
				latest.Assets[i].URL = server.URL + "/asset"
			}
			json.NewEncoder(w).Encode(latest)
		case "/asset":
			w.Write(body)
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(server.Close)
	return server
}

// built returns a release of newest whose engine asset for this host is body.
func built(newest string, body []byte) release {
	digest := sha256.Sum256(body)
	latest := hostRelease(newest)
	latest.Assets[0].SHA256 = hex.EncodeToString(digest[:])
	latest.Assets[0].Size = int64(len(body))
	latest.Assets[0].Asset = "drupack-" + newest + "-" + latest.Assets[0].Target
	return latest
}

// installed writes an executable holding content into a directory of its own.
func installed(t *testing.T, content string) string {
	t.Helper()
	executable := filepath.Join(t.TempDir(), "drupack")
	if err := os.WriteFile(executable, []byte(content), 0o755); err != nil {
		t.Fatal(err)
	}
	return executable
}

// leftovers lists what sits beside executable other than itself.
func leftovers(t *testing.T, executable string) []string {
	t.Helper()
	entries, err := os.ReadDir(filepath.Dir(executable))
	if err != nil {
		t.Fatal(err)
	}
	var names []string
	for _, entry := range entries {
		if entry.Name() != filepath.Base(executable) {
			names = append(names, entry.Name())
		}
	}
	return names
}

func hostRelease(newest string) release {
	return release{Version: newest, Assets: []asset{
		{Name: "drupack", Target: target(goruntime.GOOS, goruntime.GOARCH, musl)},
		{Name: "drupacked-demo", Target: target(goruntime.GOOS, goruntime.GOARCH, musl)},
	}}
}

func updateCheck(t *testing.T) string {
	t.Helper()
	root, err := runtime.Root(siteName, &bytes.Buffer{})
	if err != nil {
		t.Fatal(err)
	}
	content, err := os.ReadFile(filepath.Join(root, updateCheckName))
	if err != nil {
		t.Fatal(err)
	}
	return string(content)
}

func TestNewerOrdersReleaseVersions(t *testing.T) {
	for _, c := range []struct {
		candidate, current string
		want               bool
	}{
		{"1.0.0-alpha10", "1.0.0-alpha3", true},
		{"1.0.0-alpha3", "1.0.0-alpha10", false},
		{"1.0.0", "1.0.0-alpha3", true},
		{"1.0.0-alpha3", "1.0.0", false},
		{"1.0.0-beta1", "1.0.0-alpha9", true},
		{"1.0.0-alpha3", "1.0.0-alpha3", false},
		{"0.9.0", "1.0.0-alpha3", false},
		{"1.0.1", "1.0.0", true},
		{"1.10.0", "1.9.0", true},
		{"2.0.0", "1.99.99", true},
		{"1.0.0-alpha100000000000000000000", "1.0.0-alpha99999999999999999999", true},
		{"100000000000000000000.0.0", "99999999999999999999.0.0", true},
		{"1.0.0-alpha03", "1.0.0-alpha3", false},
	} {
		got, err := newer(c.candidate, c.current)
		if err != nil || got != c.want {
			t.Errorf("newer(%q, %q) = %t, %v; want %t", c.candidate, c.current, got, err, c.want)
		}
	}
}

func TestNewerRefusesAReleaseThatNamesNoVersion(t *testing.T) {
	if _, err := newer("latest", "1.0.0"); err == nil || !strings.Contains(err.Error(), `"latest"`) {
		t.Fatalf("newer accepted the version latest: %v", err)
	}
}

func TestTargetNamesTheReleaseAssets(t *testing.T) {
	for _, c := range []struct {
		goos, goarch string
		musl         bool
		want         string
	}{
		{"linux", "amd64", false, "linux-amd64"},
		{"linux", "arm64", true, "linux-arm64-musl"},
		{"darwin", "arm64", false, "macos-arm64"},
		{"windows", "amd64", false, "windows-amd64"},
	} {
		if got := target(c.goos, c.goarch, c.musl); got != c.want {
			t.Errorf("target(%s, %s, %t) = %s; want %s", c.goos, c.goarch, c.musl, got, c.want)
		}
	}
}

func TestPickNamesTheTargetsAReleaseLacksItsOwn(t *testing.T) {
	packedAs(t, "drupack", "1.0.0", true)
	latest := release{Version: "1.0.1", Assets: []asset{
		{Name: "drupack", Target: "linux-amd64"}, {Name: "drupack", Target: "macos-arm64"},
		{Name: "drupacked-demo", Target: "linux-arm64"},
	}}
	_, err := pick(latest, "linux-arm64")
	if err == nil || !strings.HasSuffix(err.Error(), "has no linux-arm64 build. It has: linux-amd64, macos-arm64") {
		t.Fatalf("pick = %v", err)
	}
}

func TestSelfUpdateRefusesBeforeAnyRequest(t *testing.T) {
	composer := t.TempDir()
	if err := os.MkdirAll(filepath.Join(composer, "vendor", "drupal", "drupack"), 0o755); err != nil {
		t.Fatal(err)
	}
	for _, c := range []struct {
		case_, name, release string
		isEngine             bool
		arguments            []string
		executable           string
		want                 string
	}{
		{"a site", "acme", "1.0.0", false, nil, filepath.Join(t.TempDir(), "acme"),
			"self-update covers the Drupack engine alone. The new release of acme comes from its publisher."},
		{"a Composer copy", "drupack", "1.0.0", true, []string{"--check"}, filepath.Join(composer, "drupack"),
			"Composer installed this drupack. Run composer update drupal/drupack, then vendor/bin/drupack-install."},
		{"a local build", "drupack", "dev", true, nil, filepath.Join(t.TempDir(), "drupack"),
			"drupack dev is a local build. self-update replaces a release alone."},
		{"an unknown option", "drupack", "1.0.0", true, []string{"--force"}, filepath.Join(t.TempDir(), "drupack"),
			"self-update takes --check alone"},
	} {
		t.Run(c.case_, func(t *testing.T) {
			packedAs(t, c.name, c.release, c.isEngine)
			requested := false
			server := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) { requested = true }))
			defer server.Close()
			err := selfUpdate(c.arguments, server.URL+"/release.json", c.executable, &bytes.Buffer{})
			if err == nil || err.Error() != c.want {
				t.Fatalf("selfUpdate = %v; want %q", err, c.want)
			}
			if requested {
				t.Fatal("the refusal sent a request")
			}
		})
	}
}

func TestCheckNamesANewerReleaseAndRecordsIt(t *testing.T) {
	packedAs(t, "drupack", "1.0.0-alpha3", true)
	server := publish(t, hostRelease("1.0.0-alpha10"))
	executable := installed(t, "old")
	var out bytes.Buffer
	if err := selfUpdate([]string{"--check"}, server.URL+"/release.json", executable, &out); err != nil {
		t.Fatal(err)
	}
	if want := "drupack 1.0.0-alpha10 is available, this is 1.0.0-alpha3. Run: drupack self-update\n"; out.String() != want {
		t.Fatalf("out = %q; want %q", out.String(), want)
	}
	if got := updateCheck(t); got != "1.0.0-alpha10" {
		t.Fatalf("update-check = %q", got)
	}
	if content, _ := os.ReadFile(executable); string(content) != "old" {
		t.Fatalf("--check changed the executable to %q", content)
	}
}

func TestCheckOnTheNewestReleaseSaysSo(t *testing.T) {
	packedAs(t, "drupack", "1.0.0", true)
	server := publish(t, hostRelease("1.0.0-alpha3"))
	var out bytes.Buffer
	if err := selfUpdate([]string{"--check"}, server.URL+"/release.json", filepath.Join(t.TempDir(), "drupack"), &out); err != nil {
		t.Fatal(err)
	}
	if want := "drupack 1.0.0 is the newest release.\n"; out.String() != want {
		t.Fatalf("out = %q; want %q", out.String(), want)
	}
	if got := updateCheck(t); got != "1.0.0-alpha3" {
		t.Fatalf("update-check = %q", got)
	}
}

func TestCheckNamesAReleaseThatAnswersNoRelease(t *testing.T) {
	packedAs(t, "drupack", "1.0.0", true)
	server := httptest.NewServer(http.NotFoundHandler())
	defer server.Close()
	err := selfUpdate([]string{"--check"}, server.URL+"/release.json", filepath.Join(t.TempDir(), "drupack"), &bytes.Buffer{})
	if err == nil || !strings.Contains(err.Error(), "answered 404 Not Found") {
		t.Fatalf("selfUpdate = %v", err)
	}
}

func TestSelfUpdateReplacesTheExecutableWithTheNewerRelease(t *testing.T) {
	packedAs(t, "drupack", "1.0.0-alpha3", true)
	server := publish(t, built("1.0.0-alpha4", []byte("new")), []byte("new")...)
	executable := installed(t, "old")
	var out bytes.Buffer
	if err := selfUpdate(nil, server.URL+"/release.json", executable, &out); err != nil {
		t.Fatal(err)
	}
	if want := "Updated drupack 1.0.0-alpha3 to 1.0.0-alpha4.\n"; out.String() != want {
		t.Fatalf("out = %q; want %q", out.String(), want)
	}
	content, err := os.ReadFile(executable)
	if err != nil || string(content) != "new" {
		t.Fatalf("the executable holds %q, %v", content, err)
	}
	if info, _ := os.Stat(executable); goruntime.GOOS != "windows" && info.Mode().Perm()&0o111 == 0 {
		t.Fatalf("the new executable has mode %v", info.Mode())
	}
	if names := leftovers(t, executable); len(names) != 0 {
		t.Fatalf("the update left %v", names)
	}
}

func TestSelfUpdateKeepsTheExecutableWhenTheDownloadFailsItsChecksum(t *testing.T) {
	packedAs(t, "drupack", "1.0.0-alpha3", true)
	server := publish(t, built("1.0.0-alpha4", []byte("new")), []byte("tampered")...)
	executable := installed(t, "old")
	err := selfUpdate(nil, server.URL+"/release.json", executable, &bytes.Buffer{})
	if err == nil || !strings.Contains(err.Error(), "does not match the size and SHA-256 release.json lists") {
		t.Fatalf("selfUpdate = %v", err)
	}
	if content, _ := os.ReadFile(executable); string(content) != "old" {
		t.Fatalf("the executable holds %q", content)
	}
	if names := leftovers(t, executable); len(names) != 0 {
		t.Fatalf("the failed update left %v", names)
	}
}

func TestSelfUpdateRefusesADirectoryItCannotWriteBeforeAnyRequest(t *testing.T) {
	if goruntime.GOOS == "windows" || os.Geteuid() == 0 {
		t.Skip("a directory mode stops no write here")
	}
	packedAs(t, "drupack", "1.0.0-alpha3", true)
	requested := false
	server := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) { requested = true }))
	defer server.Close()
	executable := installed(t, "old")
	if err := os.Chmod(filepath.Dir(executable), 0o555); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.Chmod(filepath.Dir(executable), 0o755) })
	err := selfUpdate(nil, server.URL+"/release.json", executable, &bytes.Buffer{})
	if err == nil || !strings.HasPrefix(err.Error(), "cannot write "+filepath.Dir(executable)+", which holds this drupack.") {
		t.Fatalf("selfUpdate = %v", err)
	}
	if requested {
		t.Fatal("the refusal sent a request")
	}
}

// noticeRoot returns a cache root whose update-check file holds newest, last
// written at checked, or no file when newest is "absent".
func noticeRoot(t *testing.T, newest string, checked time.Time) string {
	t.Helper()
	root := t.TempDir()
	if newest == "absent" {
		return root
	}
	path := filepath.Join(root, updateCheckName)
	if err := os.WriteFile(path, []byte(newest), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Chtimes(path, checked, checked); err != nil {
		t.Fatal(err)
	}
	return root
}

func TestUpdateNoticeNamesANewerReleaseAndChecksOnceADay(t *testing.T) {
	now := time.Date(2026, 10, 5, 12, 0, 0, 0, time.UTC)
	for _, c := range []struct {
		case_, newest string
		checked       time.Time
		line, check   bool
	}{
		{"a fresh newer release", "1.0.0-alpha4", now.Add(-time.Hour), true, false},
		{"a stale newer release", "1.0.0-alpha4", now.Add(-25 * time.Hour), true, true},
		{"a fresh equal release", "1.0.0-alpha3", now.Add(-time.Hour), false, false},
		{"an older latest release", "0.9.0", now.Add(-time.Hour), false, false},
		{"a file no check filled", "", now.Add(-time.Hour), false, false},
		{"no file", "absent", time.Time{}, false, true},
	} {
		t.Run(c.case_, func(t *testing.T) {
			packedAs(t, "drupack", "1.0.0-alpha3", true)
			t.Setenv("CI", "")
			t.Setenv("DRUPACK_NO_UPDATE_CHECK", "")
			root := noticeRoot(t, c.newest, c.checked)
			var notice bytes.Buffer
			checked := false
			updateNotice(root, installed(t, "current"), &notice, true, now, func() error { checked = true; return nil })
			line := "drupack " + c.newest + " is available, this is 1.0.0-alpha3. Run: drupack self-update\n"
			if got := notice.String() == line; got != c.line {
				t.Errorf("notice = %q; want the line: %t", notice.String(), c.line)
			}
			if checked != c.check {
				t.Errorf("checked = %t; want %t", checked, c.check)
			}
			info, err := os.Stat(filepath.Join(root, updateCheckName))
			if c.check && (err != nil || !info.ModTime().Equal(now)) {
				t.Errorf("the check did not touch update-check first: %v, %v", info, err)
			}
		})
	}
}

func TestUpdateNoticeStaysOffInCIScriptsLocalBuildsAndOnRequest(t *testing.T) {
	now := time.Date(2026, 10, 5, 12, 0, 0, 0, time.UTC)
	composer := t.TempDir()
	if err := os.MkdirAll(filepath.Join(composer, "vendor", "drupal", "drupack"), 0o755); err != nil {
		t.Fatal(err)
	}
	for _, c := range []struct {
		case_, ci, off, release string
		terminal                bool
		executable              string
	}{
		{"CI", "true", "", "1.0.0-alpha3", true, ""},
		{"DRUPACK_NO_UPDATE_CHECK", "", "1", "1.0.0-alpha3", true, ""},
		{"no terminal", "", "", "1.0.0-alpha3", false, ""},
		{"a local build", "", "", "dev", true, ""},
		{"a Composer copy", "", "", "1.0.0-alpha3", true, filepath.Join(composer, "drupack")},
	} {
		t.Run(c.case_, func(t *testing.T) {
			packedAs(t, "drupack", c.release, true)
			t.Setenv("CI", c.ci)
			t.Setenv("DRUPACK_NO_UPDATE_CHECK", c.off)
			root := noticeRoot(t, "1.0.0-alpha4", now.Add(-48*time.Hour))
			executable := c.executable
			if executable == "" {
				executable = installed(t, "current")
			}
			var notice bytes.Buffer
			checked := false
			updateNotice(root, executable, &notice, c.terminal, now, func() error { checked = true; return nil })
			if notice.Len() != 0 || checked {
				t.Fatalf("notice = %q, checked = %t; want neither", notice.String(), checked)
			}
		})
	}
}

func TestCheckRecordsNoReleaseThatLacksThisBuild(t *testing.T) {
	packedAs(t, "drupack", "1.0.0-alpha3", true)
	server := publish(t, release{Version: "1.0.0-alpha4", Assets: []asset{{Name: "drupack", Target: "plan9-amd64"}}})
	err := selfUpdate([]string{"--check"}, server.URL+"/release.json", installed(t, "old"), &bytes.Buffer{})
	if err == nil || !strings.Contains(err.Error(), "It has: plan9-amd64") {
		t.Fatalf("selfUpdate = %v", err)
	}
	root, err := runtime.Root(siteName, &bytes.Buffer{})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(root, updateCheckName)); !os.IsNotExist(err) {
		t.Fatalf("update-check exists: %v", err)
	}
}

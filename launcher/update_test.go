package main

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	goruntime "runtime"
	"strings"
	"testing"

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

// publish serves latest as release.json, with each asset's URL on the same server.
func publish(t *testing.T, latest release) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/release.json" {
			http.NotFound(w, r)
			return
		}
		json.NewEncoder(w).Encode(latest)
	}))
	t.Cleanup(server.Close)
	return server
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
	executable := filepath.Join(t.TempDir(), "drupack")
	if err := os.WriteFile(executable, []byte("old"), 0o755); err != nil {
		t.Fatal(err)
	}
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

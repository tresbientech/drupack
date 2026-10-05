package main

import (
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	goruntime "runtime"
	"strconv"
	"strings"
	"time"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// releaseURL answers with the release.json of the newest release GitHub marks latest.
const releaseURL = "https://github.com/tresbientech/drupack/releases/latest/download/release.json"

// updateCheckName is the cache root file holding the newest version a check saw.
// Its modification time is the last check.
const updateCheckName = "update-check"

// fetchTimeout bounds the release.json request, which the background check makes
// without the reader waiting on it.
const fetchTimeout = 5 * time.Second

// release is the part of release.json that build/release-files.py writes and an
// update reads.
type release struct {
	Version string  `json:"version"`
	Assets  []asset `json:"assets"`
}

type asset struct {
	Name   string `json:"name"`
	Target string `json:"target"`
	Asset  string `json:"asset"`
	URL    string `json:"url"`
	SHA256 string `json:"sha256"`
	Size   int64  `json:"size"`
}

// version matches a release tag: X.Y.Z with an optional prerelease.
var version = regexp.MustCompile(`^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?$`)

// selfUpdate runs the self-update word for the file at executable, against the
// release.json url serves.
func selfUpdate(arguments []string, url, executable string, out io.Writer) error {
	if len(arguments) > 1 || len(arguments) == 1 && arguments[0] != "--check" {
		return fmt.Errorf("self-update takes --check alone")
	}
	if !engine {
		return fmt.Errorf("self-update covers the Drupack engine alone. The new release of %s comes from its publisher.", siteName)
	}
	// composer/drupack-install writes the executable into the project root.
	if _, err := os.Stat(filepath.Join(filepath.Dir(executable), "vendor", "drupal", "drupack")); err == nil {
		return fmt.Errorf("Composer installed this %s. Run composer update drupal/drupack, then vendor/bin/drupack-install.", siteName)
	}
	if !version.MatchString(siteVersion) {
		return fmt.Errorf("%s %s is a local build. self-update replaces a release alone.", siteName, siteVersion)
	}
	check := len(arguments) == 1
	// The replacement is staged beside the executable, so a directory the reader
	// cannot write refuses before any download.
	var staged *os.File
	if !check {
		var err error
		staged, err = os.CreateTemp(filepath.Dir(executable), filepath.Base(executable)+".update-*")
		if err != nil {
			return fmt.Errorf("cannot write %s, which holds this %s. Run self-update as the user who can: %w",
				filepath.Dir(executable), siteName, err)
		}
		defer os.Remove(staged.Name())
		defer staged.Close()
	}
	root, err := runtime.Root(siteName, os.Stderr)
	if err != nil {
		return err
	}
	client, err := httpClient()
	if err != nil {
		return err
	}
	latest, err := fetchRelease(client, url)
	if err != nil {
		return err
	}
	if err := recordCheck(root, latest.Version); err != nil {
		return err
	}
	later, err := newer(latest.Version, siteVersion)
	if err != nil {
		return err
	}
	if !later {
		fmt.Fprintf(out, "%s %s is the newest release.\n", siteName, siteVersion)
		return nil
	}
	chosen, err := pick(latest, target(goruntime.GOOS, goruntime.GOARCH, musl))
	if err != nil {
		return err
	}
	if check {
		fmt.Fprintln(out, availableLine(latest.Version))
		return nil
	}
	if err := download(client, chosen, staged); err != nil {
		return err
	}
	if err := replaceExecutable(executable, staged.Name()); err != nil {
		return err
	}
	fmt.Fprintf(out, "Updated %s %s to %s.\n", siteName, siteVersion, latest.Version)
	return nil
}

// download writes chosen into staged, closes it executable, and fails unless
// its size and SHA-256 match release.json.
func download(client *http.Client, chosen asset, staged *os.File) error {
	response, err := client.Get(chosen.URL)
	if err != nil {
		return fmt.Errorf("cannot download %s: %w", chosen.URL, err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return fmt.Errorf("cannot download %s: it answered %s", chosen.URL, response.Status)
	}
	digest := sha256.New()
	written, err := io.Copy(io.MultiWriter(staged, digest), response.Body)
	if err != nil {
		return fmt.Errorf("cannot download %s: %w", chosen.URL, err)
	}
	if err := staged.Close(); err != nil {
		return err
	}
	if written != chosen.Size || hex.EncodeToString(digest.Sum(nil)) != chosen.SHA256 {
		return fmt.Errorf("the download of %s does not match the size and SHA-256 release.json lists. Nothing was replaced.", chosen.Asset)
	}
	return os.Chmod(staged.Name(), 0o755)
}

// availableLine names a newer release and the word that installs it.
func availableLine(newest string) string {
	return fmt.Sprintf("%s %s is available, this is %s. Run: %s self-update", siteName, newest, siteVersion, siteName)
}

// httpClient trusts the system roots plus the bundle DRUPACK_CA_FILE names, as
// PHP does for a re-signing proxy.
func httpClient() (*http.Client, error) {
	file := os.Getenv("DRUPACK_CA_FILE")
	if file == "" {
		return &http.Client{}, nil
	}
	bundle, err := os.ReadFile(file)
	if err != nil {
		return nil, fmt.Errorf("DRUPACK_CA_FILE: %w", err)
	}
	roots, err := x509.SystemCertPool()
	if err != nil {
		return nil, err
	}
	if !roots.AppendCertsFromPEM(bundle) {
		return nil, fmt.Errorf("DRUPACK_CA_FILE %s holds no PEM certificate", file)
	}
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.TLSClientConfig = &tls.Config{RootCAs: roots}
	return &http.Client{Transport: transport}, nil
}

func fetchRelease(client *http.Client, url string) (release, error) {
	limited := *client
	limited.Timeout = fetchTimeout
	response, err := limited.Get(url)
	if err != nil {
		return release{}, fmt.Errorf("cannot read the newest release: %w", err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return release{}, fmt.Errorf("cannot read the newest release: %s answered %s", url, response.Status)
	}
	var latest release
	if err := json.NewDecoder(response.Body).Decode(&latest); err != nil {
		return release{}, fmt.Errorf("cannot read the newest release from %s: %w", url, err)
	}
	return latest, nil
}

// recordCheck writes newest to the update-check file through a rename, so a
// start never reads half a version.
func recordCheck(root, newest string) error {
	staged, err := os.CreateTemp(root, updateCheckName+"-*")
	if err != nil {
		return err
	}
	if _, err := staged.WriteString(newest); err != nil {
		staged.Close()
		os.Remove(staged.Name())
		return err
	}
	if err := staged.Close(); err != nil {
		os.Remove(staged.Name())
		return err
	}
	return os.Rename(staged.Name(), filepath.Join(root, updateCheckName))
}

// target names the release asset built for goos and goarch, as build.Target does.
func target(goos, goarch string, musl bool) string {
	if goos == "darwin" {
		goos = "macos"
	}
	name := goos + "-" + goarch
	if musl {
		name += "-musl"
	}
	return name
}

// pick returns the engine's asset for target.
func pick(latest release, target string) (asset, error) {
	var targets []string
	for _, candidate := range latest.Assets {
		if candidate.Name != siteName {
			continue
		}
		if candidate.Target == target {
			return candidate, nil
		}
		targets = append(targets, candidate.Target)
	}
	return asset{}, fmt.Errorf("%s %s has no %s build. It has: %s", siteName, latest.Version, target, strings.Join(targets, ", "))
}

// newer reports whether candidate is a later release than current. A release
// follows its own prereleases, and prerelease digit runs compare as numbers, so
// alpha10 follows alpha3.
func newer(candidate, current string) (bool, error) {
	a := version.FindStringSubmatch(candidate)
	if a == nil {
		return false, fmt.Errorf("the newest release names the version %q, which is not X.Y.Z", candidate)
	}
	b := version.FindStringSubmatch(current)
	for i := 1; i <= 3; i++ {
		x, _ := strconv.Atoi(a[i])
		y, _ := strconv.Atoi(b[i])
		if x != y {
			return x > y, nil
		}
	}
	switch {
	case a[4] == b[4]:
		return false, nil
	case a[4] == "":
		return true, nil
	case b[4] == "":
		return false, nil
	}
	return comparePrerelease(a[4], b[4]) > 0, nil
}

var prereleaseRun = regexp.MustCompile(`\d+|\D+`)

func comparePrerelease(a, b string) int {
	x := prereleaseRun.FindAllString(a, -1)
	y := prereleaseRun.FindAllString(b, -1)
	for i := 0; i < len(x) && i < len(y); i++ {
		m, errM := strconv.Atoi(x[i])
		n, errN := strconv.Atoi(y[i])
		switch {
		case errM == nil && errN == nil && m != n:
			return m - n
		case (errM != nil || errN != nil) && x[i] != y[i]:
			return strings.Compare(x[i], y[i])
		}
	}
	return len(x) - len(y)
}

// Package node resolves a site's node value to one Node.js release and fetches
// that release's archive for each target, verified against its signed checksums.
package node

import (
	"bufio"
	"bytes"
	"crypto/sha256"
	_ "embed"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"github.com/ProtonMail/go-crypto/openpgp"
	pgperrors "github.com/ProtonMail/go-crypto/openpgp/errors"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// releaseKeys holds the gpg-only-active-keys keyring of
// https://github.com/nodejs/release-keys, exported armored.
//
//go:embed release-keys.asc
var releaseKeys []byte

// KeyringFile is where the repository keeps releaseKeys.
const KeyringFile = "launcher/internal/node/release-keys.asc"

const dist = "https://nodejs.org/dist"

// build names a target's Node build: its name in the release index's files
// list, and the end of its archive's name.
type build struct{ index, archive string }

// builds maps each target to its Node build. A musl target has no entry and
// carries no Node: Node publishes no official musl build for arm64.
var builds = map[string]build{
	"linux-amd64":   {"linux-x64", "linux-x64.tar.gz"},
	"linux-arm64":   {"linux-arm64", "linux-arm64.tar.gz"},
	"macos-amd64":   {"osx-x64-tar", "darwin-x64.tar.gz"},
	"macos-arm64":   {"osx-arm64-tar", "darwin-arm64.tar.gz"},
	"windows-amd64": {"win-x64-zip", "win-x64.zip"},
}

// Archive returns the path Resolve writes target's archive to under dir, and
// false for a target that carries no Node.
func Archive(dir, target string) (string, bool) {
	build := builds[target]
	if build.index == "" {
		return "", false
	}
	return filepath.Join(dir, target+build.archive[strings.Index(build.archive, "."):]), true
}

// Request is one resolution.
type Request struct {
	Value siteconfig.Node
	// Targets names each target as its executable's suffix, such as linux-amd64-musl.
	Targets []string
	// Dir receives the archives, one per target. An archive already there with
	// the signed hash is kept.
	Dir string
	// Dist is the release directory's URL, nodejs.org's when unset.
	Dist string
	// Keyring verifies the checksums' signature, the kept release keys when unset.
	Keyring openpgp.EntityList
}

// Resolution is the exact version, such as "24.21.0", and the verified archive
// path of each target that carries Node.
type Resolution struct {
	Version  string
	Archives map[string]string
}

// release is one entry of the release index.
type release struct {
	Version string   `json:"version"`
	Files   []string `json:"files"`
	// LTS is false, or the LTS line's name.
	LTS any `json:"lts"`
}

var (
	indexVersionRe = regexp.MustCompile(`^v([0-9]+)\.([0-9]+)\.([0-9]+)$`)
	checksumRe     = regexp.MustCompile(`^([0-9a-f]{64})  (\S+)$`)
)

// Resolve picks the release r.Value names from the release index and returns
// one verified archive per target. nodejs.org sends the index, the checksums
// and the archives, so each is checked before it is used.
func Resolve(r Request) (Resolution, error) {
	if r.Dist == "" {
		r.Dist = dist
	}
	if r.Keyring == nil {
		keyring, err := openpgp.ReadArmoredKeyRing(bytes.NewReader(releaseKeys))
		if err != nil {
			return Resolution{}, fmt.Errorf("%s: %w", KeyringFile, err)
		}
		r.Keyring = keyring
	}
	index, err := fetch(r.Dist + "/index.json")
	if err != nil {
		return Resolution{}, err
	}
	var releases []release
	if err := json.Unmarshal(index, &releases); err != nil {
		return Resolution{}, fmt.Errorf("%s/index.json: %w", r.Dist, err)
	}
	chosen, version, err := choose(r.Value, releases)
	if err != nil {
		return Resolution{}, err
	}

	names := map[string]string{}
	for _, target := range r.Targets {
		build := builds[target]
		if build.index == "" {
			continue
		}
		if !slices.Contains(chosen.Files, build.index) {
			return Resolution{}, fmt.Errorf("Node %s has no build for %s, which needs %s", version, target, build.index)
		}
		names[target] = "node-" + chosen.Version + "-" + build.archive
	}
	archives := map[string]string{}
	if len(names) == 0 {
		return Resolution{Version: version, Archives: archives}, nil
	}

	sums, err := signedChecksums(r, chosen.Version)
	if err != nil {
		return Resolution{}, err
	}
	if err := os.MkdirAll(r.Dir, 0o755); err != nil {
		return Resolution{}, err
	}
	for target, name := range names {
		want, ok := sums[name]
		if !ok {
			return Resolution{}, fmt.Errorf("the signed SHASUMS256.txt of Node %s lists no %s", version, name)
		}
		path, _ := Archive(r.Dir, target)
		if err := download(r.Dist+"/"+chosen.Version+"/"+name, path, want); err != nil {
			return Resolution{}, err
		}
		archives[target] = path
	}
	return Resolution{Version: version, Archives: archives}, nil
}

// choose picks the newest release value admits and returns it with its version
// without the leading v.
func choose(value siteconfig.Node, releases []release) (release, string, error) {
	var chosen release
	var newest [3]int
	found := false
	for _, candidate := range releases {
		parts := indexVersionRe.FindStringSubmatch(candidate.Version)
		if parts == nil {
			return release{}, "", fmt.Errorf("the release index names version %q, which is not vMAJOR.MINOR.PATCH", candidate.Version)
		}
		var number [3]int
		for i := range number {
			number[i], _ = strconv.Atoi(parts[i+1])
		}
		var admitted bool
		switch {
		case value == siteconfig.NodeLTS:
			_, admitted = candidate.LTS.(string)
		case value.Exact():
			admitted = string(value) == strings.TrimPrefix(candidate.Version, "v")
		default:
			admitted = string(value) == parts[1]
		}
		if admitted && (!found || newer(number, newest)) {
			chosen, newest, found = candidate, number, true
		}
	}
	if !found {
		switch {
		case value == siteconfig.NodeLTS:
			return release{}, "", errors.New("the Node release index names no LTS release")
		case value.Exact():
			return release{}, "", fmt.Errorf("node %s is not in the Node release index", value)
		default:
			return release{}, "", fmt.Errorf("node %s names no release in the Node release index", value)
		}
	}
	return chosen, strings.TrimPrefix(chosen.Version, "v"), nil
}

func newer(a, b [3]int) bool {
	for i := range a {
		if a[i] != b[i] {
			return a[i] > b[i]
		}
	}
	return false
}

// signedChecksums fetches a release's SHASUMS256.txt, checks its detached
// signature against the keyring, and maps each file name to its SHA-256.
func signedChecksums(r Request, tag string) (map[string]string, error) {
	base := r.Dist + "/" + tag + "/SHASUMS256.txt"
	sums, err := fetch(base)
	if err != nil {
		return nil, err
	}
	signature, err := fetch(base + ".sig")
	if err != nil {
		return nil, err
	}
	if _, err := openpgp.CheckDetachedSignature(r.Keyring, bytes.NewReader(sums), bytes.NewReader(signature), nil); err != nil {
		if errors.Is(err, pgperrors.ErrUnknownIssuer) {
			return nil, fmt.Errorf("%s is signed by a key %s does not hold: add Node's new release key from https://github.com/nodejs/release-keys", base, KeyringFile)
		}
		return nil, fmt.Errorf("%s: the signature does not verify: %w", base, err)
	}
	checksums := map[string]string{}
	lines := bufio.NewScanner(bytes.NewReader(sums))
	for lines.Scan() {
		parts := checksumRe.FindStringSubmatch(lines.Text())
		if parts == nil {
			return nil, fmt.Errorf("%s holds the line %q, which is not a SHA-256 and a file name", base, lines.Text())
		}
		checksums[parts[2]] = parts[1]
	}
	return checksums, lines.Err()
}

// download writes url to path unless path already holds the file with SHA-256
// want, and refuses a download with any other hash.
func download(url, path, want string) error {
	if got, err := hashFile(path); err == nil && got == want {
		return nil
	}
	response, err := http.Get(url)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return fmt.Errorf("%s: %s", url, response.Status)
	}
	partial := path + ".part"
	file, err := os.Create(partial)
	if err != nil {
		return err
	}
	hash := sha256.New()
	_, err = io.Copy(io.MultiWriter(file, hash), response.Body)
	if closeErr := file.Close(); err == nil {
		err = closeErr
	}
	if err != nil {
		os.Remove(partial)
		return fmt.Errorf("%s: %w", url, err)
	}
	if got := hex.EncodeToString(hash.Sum(nil)); got != want {
		os.Remove(partial)
		return fmt.Errorf("%s has SHA-256 %s, and the signed SHASUMS256.txt names %s", url, got, want)
	}
	return os.Rename(partial, path)
}

func hashFile(path string) (string, error) {
	file, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer file.Close()
	hash := sha256.New()
	if _, err := io.Copy(hash, file); err != nil {
		return "", err
	}
	return hex.EncodeToString(hash.Sum(nil)), nil
}

func fetch(url string) ([]byte, error) {
	response, err := http.Get(url)
	if err != nil {
		return nil, err
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("%s: %s", url, response.Status)
	}
	content, err := io.ReadAll(response.Body)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", url, err)
	}
	return content, nil
}

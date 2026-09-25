// Package siteconfig reads a site's drupack.yml and writes the site.json that
// the build, launch.php and the conformance suite read in its place.
package siteconfig

import (
	"encoding/json"
	"fmt"
	"maps"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"slices"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"sigs.k8s.io/yaml"
)

// FileName is the site contract a site repository holds beside composer.json.
const FileName = "drupack.yml"

// OutputName is the normalized form every other reader takes.
const OutputName = "site.json"

// Libcs lists the values libc takes, and the runtimes each packs in the order
// the launcher tries them: the one needing a host loader first.
var Libcs = map[string][]string{
	"both":  {"glibc", "musl"},
	"glibc": {"glibc"},
	"musl":  {"musl"},
}

// Platforms lists the targets this release builds, and each one's GOARCH.
var Platforms = map[string]string{
	"linux-amd64": "amd64",
	"linux-arm64": "arm64",
}

// Site is one site's contract. Its JSON tags are the site.json shape.
type Site struct {
	Name   string `json:"name"`
	Port   int    `json:"port"`
	Recipe string `json:"recipe"`
	// Settings names a PHP file in the site that the generated settings.php requires last.
	Settings   string   `json:"settings"`
	SiteName   string   `json:"site_name"`
	Languages  []string `json:"languages"`
	SmokePaths []string `json:"smoke_paths"`
	Extensions []string `json:"extensions"`
	Platforms  []string `json:"platforms"`
	Libc       string   `json:"libc"`
	// Writable names the application directories the site writes at runtime,
	// relative to the project root.
	Writable []string `json:"writable"`
	// Read sets Docroot from composer.json. Parse leaves it empty.
	Docroot string `json:"docroot"`
}

var (
	extensionRe    = regexp.MustCompile(`^[a-z][a-z0-9_]*$`)
	languageRe     = regexp.MustCompile(`^[a-z]{2,3}(-[a-z]+)?$`)
	relativePathRe = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_./-]*$`)
	pathRe         = regexp.MustCompile(`^/[A-Za-z0-9_./~-]*$`)
)

// Parse reads drupack.yml content. The site author writes that file, so every
// field is checked here and an error names the field it rejects.
func Parse(content []byte) (Site, error) {
	var site Site
	if err := yaml.UnmarshalStrict(content, &site); err != nil {
		return Site{}, fmt.Errorf("%s: %w", FileName, err)
	}
	if site.Port == 0 {
		site.Port = 7225
	}
	if site.Languages == nil {
		site.Languages = []string{}
	}
	if site.SmokePaths == nil {
		site.SmokePaths = []string{"/"}
	}
	if site.Extensions == nil {
		site.Extensions = []string{}
	}
	if site.Platforms == nil {
		site.Platforms = []string{"linux-amd64"}
	}
	if site.Libc == "" {
		site.Libc = "both"
	}
	if site.Writable == nil {
		site.Writable = []string{}
	}
	return site, validate(site)
}

func validate(site Site) error {
	if !runtime.MintedSegment(site.Name) {
		return fieldError("name", "%q must match %s", site.Name, runtime.MintedSegmentPattern)
	}
	if site.Port < 1 || site.Port > 65535 {
		return fieldError("port", "%d is outside 1 to 65535", site.Port)
	}
	if site.Recipe != "" && (!relativePathRe.MatchString(site.Recipe) || hasParentSegment(site.Recipe)) {
		return fieldError("recipe", "%q must be a relative path inside the project", site.Recipe)
	}
	if site.Settings != "" && (!relativePathRe.MatchString(site.Settings) || hasParentSegment(site.Settings)) {
		return fieldError("settings", "%q must be a relative path inside the project", site.Settings)
	}
	if strings.TrimSpace(site.SiteName) == "" || strings.ContainsFunc(site.SiteName, isControl) {
		return fieldError("site_name", "%q must be a non-empty single line", site.SiteName)
	}
	for _, language := range site.Languages {
		// English is the source language, so drupal.org publishes no translation for it.
		if !languageRe.MatchString(language) || language == "en" {
			return fieldError("languages", "%q is not a translation language code", language)
		}
	}
	for _, path := range site.SmokePaths {
		if !pathRe.MatchString(path) || hasParentSegment(path) {
			return fieldError("smoke_paths", "%q must be an absolute site path", path)
		}
	}
	for _, extension := range site.Extensions {
		if !extensionRe.MatchString(extension) {
			return fieldError("extensions", "%q is not a PHP extension name", extension)
		}
	}
	if len(site.Platforms) == 0 {
		return fieldError("platforms", "names no target")
	}
	for _, platform := range site.Platforms {
		if _, ok := Platforms[platform]; !ok {
			return fieldError("platforms", "%q is not one of %s", platform, strings.Join(slices.Sorted(maps.Keys(Platforms)), ", "))
		}
	}
	if _, ok := Libcs[site.Libc]; !ok {
		return fieldError("libc", "%q is not one of %s", site.Libc, strings.Join(slices.Sorted(maps.Keys(Libcs)), ", "))
	}
	for i, directory := range site.Writable {
		if !relativePathRe.MatchString(directory) || hasParentSegment(directory) || path.Clean(directory) != directory {
			return fieldError("writable", "%q must be a clean relative path inside the project", directory)
		}
		// An upgrade carries each directory's entries over once, so no two may overlap.
		for _, other := range site.Writable[:i] {
			if directory == other || strings.HasPrefix(directory, other+"/") || strings.HasPrefix(other, directory+"/") {
				return fieldError("writable", "%q and %q overlap", other, directory)
			}
		}
	}
	return nil
}

func fieldError(field, format string, arguments ...any) error {
	return fmt.Errorf("%s: %s: %s", FileName, field, fmt.Sprintf(format, arguments...))
}

func hasParentSegment(path string) bool {
	for _, segment := range strings.Split(path, "/") {
		if segment == ".." {
			return true
		}
	}
	return false
}

func isControl(r rune) bool {
	return r < 0x20 || r == 0x7f
}

// Read parses the drupack.yml in directory and takes the docroot from its composer.json.
func Read(directory string) (Site, error) {
	content, err := os.ReadFile(filepath.Join(directory, FileName))
	if err != nil {
		return Site{}, err
	}
	site, err := Parse(content)
	if err != nil {
		return Site{}, err
	}
	if site.Settings != "" {
		if info, err := os.Stat(filepath.Join(directory, site.Settings)); err != nil || !info.Mode().IsRegular() {
			return Site{}, fieldError("settings", "%q is not a file in the site", site.Settings)
		}
	}
	site.Docroot, err = docroot(directory)
	return site, err
}

// docroot reads the web root drupal/core-composer-scaffold writes to. The site
// author sets it, so it is checked like a drupack.yml field.
func docroot(directory string) (string, error) {
	content, err := os.ReadFile(filepath.Join(directory, "composer.json"))
	if err != nil {
		return "", err
	}
	var composer struct {
		Extra struct {
			Scaffold struct {
				Locations struct {
					WebRoot string `json:"web-root"`
				} `json:"locations"`
			} `json:"drupal-scaffold"`
		} `json:"extra"`
	}
	if err := json.Unmarshal(content, &composer); err != nil {
		return "", fmt.Errorf("composer.json: %w", err)
	}
	webRoot := composer.Extra.Scaffold.Locations.WebRoot
	cleaned := path.Clean(webRoot)
	if webRoot == "" || cleaned == "." || !relativePathRe.MatchString(cleaned) || hasParentSegment(cleaned) {
		return "", fmt.Errorf("composer.json: extra.drupal-scaffold.locations.web-root: %q must name a directory inside the project", webRoot)
	}
	return cleaned, nil
}

// Write stores site as site.json in directory.
func Write(site Site, directory string) error {
	content, err := json.MarshalIndent(site, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(directory, OutputName), append(content, '\n'), 0o644)
}

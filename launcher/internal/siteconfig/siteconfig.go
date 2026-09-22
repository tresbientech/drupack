// Package siteconfig reads a site's drupack.yml and writes the site.json that
// the build, launch.php and the conformance suite read in its place.
package siteconfig

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"sigs.k8s.io/yaml"
)

// FileName is the site contract a site repository holds beside composer.json.
const FileName = "drupack.yml"

// OutputName is the normalized form every other reader takes.
const OutputName = "site.json"

// Site is one site's contract. Its JSON tags are the site.json shape.
type Site struct {
	Name       string   `json:"name"`
	Port       int      `json:"port"`
	Recipe     string   `json:"recipe"`
	SiteName   string   `json:"site_name"`
	Languages  []string `json:"languages"`
	SmokePaths []string `json:"smoke_paths"`
	Extensions []string `json:"extensions"`
}

var (
	languageRe = regexp.MustCompile(`^[a-z]{2,3}(-[a-z]+)?$`)
	recipeRe   = regexp.MustCompile(`^[A-Za-z0-9_][A-Za-z0-9_./-]*$`)
	pathRe     = regexp.MustCompile(`^/[A-Za-z0-9_./~-]*$`)
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
	return site, validate(site)
}

func validate(site Site) error {
	if !runtime.MintedSegment(site.Name) {
		return fieldError("name", "%q must match %s", site.Name, runtime.MintedSegmentPattern)
	}
	if site.Port < 1 || site.Port > 65535 {
		return fieldError("port", "%d is outside 1 to 65535", site.Port)
	}
	if !recipeRe.MatchString(site.Recipe) || hasParentSegment(site.Recipe) {
		return fieldError("recipe", "%q must be a relative path inside the project", site.Recipe)
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
	if len(site.Extensions) > 0 {
		return fieldError("extensions", "site additions to the PHP extension list are not supported yet")
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

// Read parses the drupack.yml in directory.
func Read(directory string) (Site, error) {
	content, err := os.ReadFile(filepath.Join(directory, FileName))
	if err != nil {
		return Site{}, err
	}
	return Parse(content)
}

// Write stores site as site.json in directory.
func Write(site Site, directory string) error {
	content, err := json.MarshalIndent(site, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(filepath.Join(directory, OutputName), append(content, '\n'), 0o644)
}

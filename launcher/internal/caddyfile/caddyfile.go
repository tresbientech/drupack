// Package caddyfile renders the Caddy fragments into the one Caddyfile a start
// serves with. Go owns the values and the fragments own the rules.
package caddyfile

import (
	"bytes"
	"embed"
	"fmt"
	"strconv"
	"strings"
	"text/template"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/tls"
)

//go:embed fragments/*.caddy
var fragments embed.FS

// Modes pick the log and the files route.
const (
	Engine = "engine"
	Site   = "site"
)

// Config is every value a rendered Caddyfile holds. A path is a canonical path.
type Config struct {
	// Mode is Engine or Site.
	Mode string
	// ID names the served state. The readiness probe and the PHP cache id read it.
	ID      string
	Bind    string
	Port    int
	Docroot string
	TLS     tls.Choice
	// ProjectRules is the project's drupack.caddy, "" for a project without one.
	ProjectRules string
	// Mercure is nil for a project that serves no hub.
	Mercure *Mercure
	// Static is nil for a project that serves no static listener.
	Static *Static
	// FilesDir and LogPath are the Site mode's.
	FilesDir string
	LogPath  string
}

type Mercure struct {
	URL, Publisher, Subscriber, DB string
}

type Static struct {
	Bind string
	Port int
	// Root is the directory the export fills.
	Root string
	// Dynamic holds the patterns Drupal answers ahead of the files.
	Dynamic []string
}

// Addresses lists the names the TLS source serves on port. A source with no
// certificate answers any address of the bind.
func (c Config) Addresses(port int) string {
	p := strconv.Itoa(port)
	if c.TLS.Source == "none" {
		return quote("http://:" + p)
	}
	names := []string{c.TLS.Alias, "localhost", "127.0.0.1", "[::1]"}
	for i, name := range names {
		names[i] = quote("https://" + name + ":" + p)
	}
	return strings.Join(names, ", ")
}

// quote writes one value as a Caddyfile token. Inside double quotes the lexer
// reads \" as a quote and keeps every other backslash.
func quote(value string) string {
	return `"` + strings.ReplaceAll(value, `"`, `\"`) + `"`
}

// indent puts n tabs before each line that holds text.
func indent(n int, text string) string {
	lines := strings.Split(text, "\n")
	for i, line := range lines {
		if line != "" {
			lines[i] = strings.Repeat("\t", n) + line
		}
	}
	return strings.Join(lines, "\n")
}

// RefuseBraces refuses a path holding { or }, which Caddy reads as a placeholder.
func RefuseBraces(name, value string) error {
	if strings.ContainsAny(value, "{}") {
		return fmt.Errorf("%s must not contain { or }: %s", name, value)
	}
	return nil
}

var templates = func() *template.Template {
	root := template.New("")
	root.Funcs(template.FuncMap{
		"quote":  quote,
		"indent": indent,
		"include": func(name string, data any) (string, error) {
			var out bytes.Buffer
			err := root.ExecuteTemplate(&out, name, data)
			return strings.TrimRight(out.String(), "\n"), err
		},
	})
	return template.Must(root.ParseFS(fragments, "fragments/*.caddy"))
}()

// Render writes the Caddyfile for c.
func Render(c Config) ([]byte, error) {
	var out bytes.Buffer
	if err := templates.ExecuteTemplate(&out, "caddyfile.caddy", c); err != nil {
		return nil, err
	}
	return out.Bytes(), nil
}

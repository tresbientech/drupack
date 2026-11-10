// Package caddyfile renders the Caddy fragments into the one Caddyfile a start
// serves with. Go owns the values and the fragments own the rules.
package caddyfile

import (
	"bytes"
	"embed"
	"errors"
	"fmt"
	"slices"
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
	// AccessLog is the file both ports log their requests to, "" for none.
	AccessLog string
	// ErrorLog is the file that takes PHP's and Caddy's warnings and errors, "" for none.
	ErrorLog string
	// TrustedProxies are the CIDR ranges whose forwarded headers name the client, none by default.
	TrustedProxies []string
}

type Mercure struct {
	URL, Publisher, Subscriber, DB string
	// ProjectRules is the project's drupack.mercure.caddy, "" for a project without one.
	ProjectRules string
}

type Static struct {
	Bind string
	Port int
	// Root is the directory the export fills.
	Root string
	// Dynamic holds the patterns Drupal answers ahead of the files.
	Dynamic []string
}

// Addresses lists the names a listener on bind and port answers. A source with
// no certificate answers any name on a bind beyond this computer, which readers
// on the network reach by its address or a name of their own. On loopback it
// answers the start's own names alone, so a page that points its own name at
// 127.0.0.1 reaches nothing.
func (c Config) Addresses(bind string, port int) (string, error) {
	p := strconv.Itoa(port)
	scheme, names := "https://", []string{c.TLS.Alias, "localhost", "127.0.0.1", "::1"}
	if c.TLS.Source == "none" {
		if !tls.Loopback(bind) {
			return quote("http://:" + p)
		}
		scheme, names = "http://", append(names, bind, c.TLS.Host)
	}
	var addresses []string
	for _, name := range names {
		if name == "" {
			continue
		}
		if strings.Contains(name, ":") {
			name = "[" + name + "]"
		}
		address, err := quote(scheme + name + ":" + p)
		if err != nil {
			return "", err
		}
		if !slices.Contains(addresses, address) {
			addresses = append(addresses, address)
		}
	}
	return strings.Join(addresses, ", "), nil
}

// unquotable is a value no Caddyfile token can hold.
type unquotable struct {
	value string
}

func (u unquotable) Error() string {
	return "A Caddyfile token cannot hold a backtick together with a backslash before a quote or at the end: " + u.value
}

// quote writes one value as a Caddyfile token. Inside double quotes the lexer
// reads \" as a quote and keeps every other backslash, so no escape gives a
// backslash before a quote or at the end. Between backticks it keeps every
// character up to the next backtick.
func quote(value string) (string, error) {
	switch {
	case !strings.Contains(value, `\"`) && !strings.HasSuffix(value, `\`):
		return `"` + strings.ReplaceAll(value, `"`, `\"`) + `"`, nil
	case !strings.Contains(value, "`"):
		return "`" + value + "`", nil
	}
	return "", unquotable{value}
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

func Render(c Config) ([]byte, error) {
	var out bytes.Buffer
	if err := templates.ExecuteTemplate(&out, "caddyfile.caddy", c); err != nil {
		// The template's position means nothing to the reader who chose the value.
		var refused unquotable
		if errors.As(err, &refused) {
			return nil, refused
		}
		return nil, err
	}
	return out.Bytes(), nil
}

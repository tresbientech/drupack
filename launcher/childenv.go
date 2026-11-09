package main

import (
	"os"
	"path/filepath"
	"slices"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/caddyfile"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

// Kind names the child an environment is for. Reader is the runtime running a line the
// reader typed for it: php, php-cli and Node's words.
type Kind int

const (
	Reader Kind = iota
	Server
	Operate
	Drush
	Export
)

// Facts is what a start knows when it launches a child. A start fills it as it learns
// things, and an unset field leaves the inherited variable as it is.
type Facts struct {
	Launch
	// Inputs are the typed start options, NAME=VALUE entries PHP's check and prepare read.
	Inputs []string
	// Data, Files and Scratch are Site data's resolved paths in canonical form. StaticRoot
	// is "" for a site without a static listener.
	Data, Files, Scratch, StaticRoot string
	// Host and URL are the served address: the host Drupal trusts and the URL its links name.
	Host, URL string
	// CAFile is the bundle an HTTPS start wrote.
	CAFile string
	Hub    *caddyfile.Mercure
	// SiteApp is the application laid in Site data.
	SiteApp string
	// Bin is the application whose bin/ leads PATH, and Script the Drush script.
	Bin, Script string
	// Open is the link the server opens a browser on. Alias is the drupack.site name.
	Open, Alias string
	// ID, Probe, StopRecord and TLSRoot are what the runtime's watch reads: the token and
	// address of the readiness probe, the stop record and the root a client verifies HTTPS with.
	ID, Probe, StopRecord, TLSRoot string
}

// value returns what a child of kind k reads under name, "" when it is unset.
func (f Facts) value(k Kind, name string) string {
	e := environ{entries: f.Env(k), fold: f.FoldCase}
	value, _ := e.get(name)
	return value
}

// Env builds the environment of a child of kind k from the launcher's own. A variable this
// start does not use is dropped, since a start can run from another Drupack server's process.
func (f Facts) Env(k Kind) []string {
	l := f.Launch
	directory := filepath.Dir(l.Entry)
	e := environ{entries: slices.Clone(l.Parent), fold: l.FoldCase}
	for _, name := range loaderVariables {
		e.drop(name)
	}
	// FrankenPHP's static binary otherwise resolves php.ini from wherever it runs.
	e.put("PHPRC=" + directory)
	// A caller behind a TLS-inspection proxy names their own bundle.
	if _, set := e.get("DRUPACK_CA_FILE"); !set {
		e.put("DRUPACK_CA_FILE=" + runtime.Canonical(filepath.Join(directory, caFileName)))
	}
	// The runtime and operate.php are shared by every site built on this engine, so
	// the site's name and release reach them from here.
	e.put("DRUPACK_RUNTIME_NAME=" + l.Name)
	e.put("DRUPACK_RUNTIME_SITE_VERSION=" + l.Version)
	e.put("DRUPACK_RUNTIME_COMPONENTS=" + l.Components)
	if l.Engine {
		// The engine serves a folder named from the reader's directory. An application
		// directory would make the entry point change into it.
		e.drop("DRUPACK_RUNTIME_APP_DIR")
	} else {
		// The entry point changes into this directory, and PHP, Caddy and the reader's
		// terminal read it, so it takes the canonical form.
		e.put("DRUPACK_RUNTIME_APP_DIR=" + runtime.Canonical(l.Application))
	}
	if l.NodeBin != "" {
		path, _ := e.get("PATH")
		e.put("PATH=" + l.NodeBin + string(os.PathListSeparator) + path)
	}
	if k == Reader {
		return e.entries
	}
	if f.SiteApp != "" {
		e.put("DRUPACK_RUNTIME_APP_DIR=" + runtime.Canonical(f.SiteApp))
	}
	if f.Data != "" {
		e.put("DRUPACK_RUNTIME_DATA_DIR=" + f.Data)
		e.put("DRUPACK_RUNTIME_FILES_DIR=" + f.Files)
		// Drupal matches its trusted host patterns against the request's host, which keeps
		// an IPv6 address's brackets.
		e.put("DRUPACK_RUNTIME_HOST=" + urlHost(f.Host))
		// The address a reader types, never the bind address. Without it Drupal falls back
		// to http://default, and every printed or mailed link names an unreachable host.
		e.put("DRUSH_OPTIONS_URI=" + f.URL)
		for _, name := range []string{"TMPDIR", "TEMP", "TMP", "XDG_DATA_HOME", "XDG_CONFIG_HOME"} {
			e.put(name + "=" + f.Scratch)
		}
		if f.StaticRoot != "" {
			e.put("DRUPACK_RUNTIME_STATIC_ROOT=" + f.StaticRoot)
		}
	}
	if f.CAFile != "" {
		e.put("DRUPACK_CA_FILE=" + runtime.Canonical(f.CAFile))
	}
	if f.Hub != nil {
		e.put("DRUPACK_MERCURE_PUBLISHER_SECRET=" + f.Hub.Publisher)
		e.put("DRUPACK_MERCURE_SUBSCRIBER_SECRET=" + f.Hub.Subscriber)
		e.put("DRUPACK_MERCURE_URL=" + f.Hub.URL)
	}
	if f.Bin != "" {
		// Drush's, Tome's and a request's child processes run the php on PATH, which bin/
		// points back at this runtime.
		path, _ := e.get("PATH")
		e.put("PATH=" + filepath.Join(f.Bin, "bin") + string(os.PathListSeparator) + path)
	}
	if f.Script != "" {
		e.put("DRUPACK_DRUSH_SCRIPT=" + runtime.Canonical(f.Script))
	}
	switch k {
	case Operate:
		for _, input := range f.Inputs {
			e.put(input)
		}
	case Server:
		// The server opens the browser once the site answers, so a start that opens none
		// clears whatever the environment it inherited held.
		e.drop("DRUPACK_RUNTIME_OPEN")
		if f.Open != "" {
			e.put("DRUPACK_RUNTIME_OPEN=" + f.Open)
		}
		e.put("DRUPACK_RUNTIME_ID=" + f.ID)
		e.put("DRUPACK_RUNTIME_URL=" + f.Probe)
		e.put("DRUPACK_RUNTIME_STOP_RECORD=" + runtime.Canonical(f.StopRecord))
		if f.TLSRoot != "" {
			e.put("DRUPACK_RUNTIME_TLS_ROOT=" + f.TLSRoot)
		}
		if !f.Engine {
			// Every step that needed the administrator password has run. The server passes
			// its environment to every process it starts, including PHP code a site runs.
			e.drop("DRUPACK_ADMIN_PASSWORD")
			// settings.php trusts the drupack.site name as a request host.
			e.put("DRUPACK_RUNTIME_ALIAS=" + f.Alias)
		}
	}
	return e.entries
}

type environ struct {
	entries []string
	fold    bool
}

func (e *environ) named(entry, name string) bool {
	key, _, _ := strings.Cut(entry, "=")
	if e.fold {
		return strings.EqualFold(key, name)
	}
	return key == name
}

func (e *environ) get(name string) (string, bool) {
	for _, entry := range e.entries {
		if e.named(entry, name) {
			_, value, _ := strings.Cut(entry, "=")
			return value, true
		}
	}
	return "", false
}

func (e *environ) drop(name string) {
	e.entries = slices.DeleteFunc(e.entries, func(entry string) bool { return e.named(entry, name) })
}

func (e *environ) put(entry string) {
	name, _, _ := strings.Cut(entry, "=")
	e.drop(name)
	e.entries = append(e.entries, entry)
}

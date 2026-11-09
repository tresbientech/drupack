package main

import (
	"os"
	"path/filepath"
	"slices"
	"strings"

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

// Facts is what a start knows when it launches a child.
type Facts struct {
	Launch
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

package main

import (
	"os"
	"path/filepath"
	"slices"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
)

var nodeWords = []string{"node", "npm", "npx"}

// loaderVariables are the glibc dynamic loader's own controls. A Linux release
// carries a glibc runtime, which runs under the host's loader, so a caller who
// sets any of these loads code of their choosing into the PHP process. The
// launcher drops them on every platform: a static musl runtime and a Windows
// runtime ignore them, so nothing distinguishes the cases.
var loaderVariables = []string{
	"LD_PRELOAD",
	"LD_LIBRARY_PATH",
	"LD_AUDIT",
	"GLIBC_TUNABLES",
}

const caFileName = "cacert.pem"

// Launch holds everything one start hands the runtime. Paths are native.
type Launch struct {
	Args        []string // os.Args
	Parent      []string // os.Environ()
	Entry       string
	Application string
	// AppChecksum identifies the release's application, which a site's own copy in Site data must match.
	AppChecksum string
	Root        string
	// NodeBin is the Node release's executable directory, empty for a file that carries none.
	NodeBin string
	// TLSDir is the folder holding the user's local root, absolute, empty when none is named.
	TLSDir     string
	Name       string
	Version    string
	Components string
	Engine     bool
	// ConsoleOwned is true on Windows when a file manager created the console.
	ConsoleOwned bool
	// FoldCase matches variable names whatever their case, as Windows does.
	FoldCase bool
}

// Command is the process a start replaces itself with, or runs as a child on Windows.
type Command struct {
	Path string
	Args []string
	Env  []string
	// Dir is the directory Windows starts the child in. A Unix exec keeps the
	// working directory it has.
	Dir string
}

// Plan returns the runtime's command for a line it reads itself: php, php-cli, the
// version flags, and Node's words where this file carries Node.
func Plan(l Launch) Command {
	if len(l.Args) > 1 && l.NodeBin != "" && slices.Contains(nodeWords, l.Args[1]) {
		path := filepath.Join(l.NodeBin, nodeFile(l.Args[1]))
		return Command{Path: path, Args: append([]string{path}, l.Args[2:]...), Env: environment(l)}
	}
	return Command{Path: l.Entry, Args: l.Args, Env: environment(l)}
}

// environment builds the runtime's environment from the launcher's own. A variable this
// start does not use is dropped, since a start can run from another Drupack server's process.
func environment(l Launch) []string {
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

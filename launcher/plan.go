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
	Launcher    string
	Root        string
	// NodeBin is the Node release's executable directory, empty for a file that carries none.
	NodeBin string
	// TLSDir is the folder holding the user's local root, empty when the system names none.
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
}

func Plan(l Launch) Command {
	return Command{
		Path: l.Entry,
		Args: arguments(l.Args, l.Application, l.Engine, l.NodeBin != ""),
		Env:  environment(l),
	}
}

// arguments turns the reader's words into the runtime's. A site's php-cli, the version flags, a Node
// word where this file carries Node, and the Engine's php pass unchanged. Every other line
// reaches launch.php, or the Engine's serve.php, through php-cli.
func arguments(args []string, application string, engine, node bool) []string {
	switch {
	case len(args) > 1 && !engine && args[1] == "php-cli":
	case len(args) == 2 && (args[1] == "--version" || args[1] == "-v"):
	case len(args) > 1 && node && slices.Contains(nodeWords, args[1]):
	case len(args) > 1 && engine && args[1] == "php":
	default:
		script := "launch.php"
		if engine {
			script = "serve.php"
		}
		return append([]string{args[0], "php-cli", filepath.Join(application, script)}, args[1:]...)
	}
	return args
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
	if _, set := e.get("DRUPACK_TLS_DIR"); !set && l.TLSDir != "" {
		e.put("DRUPACK_TLS_DIR=" + runtime.Canonical(l.TLSDir))
	}
	// The runtime and launch.php are shared by every site built on this engine, so
	// the site's name and release reach them from here.
	e.put("DRUPACK_RUNTIME_NAME=" + l.Name)
	e.put("DRUPACK_RUNTIME_SITE_VERSION=" + l.Version)
	e.put("DRUPACK_RUNTIME_COMPONENTS=" + l.Components)
	e.put("DRUPACK_RUNTIME_LAUNCHER=" + runtime.Canonical(l.Launcher))
	e.put("DRUPACK_RUNTIME_INVOKED=" + l.Args[0])
	if l.Engine {
		// The engine serves a folder named from the reader's directory. An application
		// directory would send the runtime down the site path, which refuses the php word.
		e.drop("DRUPACK_RUNTIME_APP_DIR")
		e.put("DRUPACK_RUNTIME_CACHE_ROOT=" + runtime.Canonical(l.Root))
	} else {
		e.drop("DRUPACK_RUNTIME_CACHE_ROOT")
		// The entry point changes into this directory, and PHP, Caddy and the reader's
		// terminal read it, so it takes the canonical form.
		e.put("DRUPACK_RUNTIME_APP_DIR=" + runtime.Canonical(l.Application))
	}
	if l.NodeBin == "" {
		e.drop("DRUPACK_RUNTIME_NODE")
	} else {
		path, _ := e.get("PATH")
		e.put("PATH=" + l.NodeBin + string(os.PathListSeparator) + path)
		e.put("DRUPACK_RUNTIME_NODE=" + runtime.Canonical(l.NodeBin))
	}
	if l.ConsoleOwned {
		e.put("DRUPACK_RUNTIME_CONSOLE_OWNED=1")
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

package main

import (
	"path/filepath"
	"slices"
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
	Args        []string // os.Args, without the console and child words
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
	// Musl marks a file packed over a musl runtime.
	Musl bool
	// Child is nil for a start the reader ran, and holds what a detaching parent passed its child.
	Child *detachedChild
	// ConsoleOwned is true on Windows when a file manager created the console.
	ConsoleOwned bool
	// FoldCase matches variable names whatever their case, as Windows does.
	FoldCase bool
}

// lookup reads name in the environment this process started with.
func (l Launch) lookup(name string) (string, bool) {
	e := environ{entries: l.Parent, fold: l.FoldCase}
	return e.get(name)
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
// version flags, and Node's words where this file carries Node. The runtime's
// command line belongs to Caddy, whose own --version it cannot replace, so a
// version flag becomes the runtime's drupack-version.
func Plan(l Launch) Command {
	if len(l.Args) > 1 && l.NodeBin != "" && slices.Contains(nodeWords, l.Args[1]) {
		path := filepath.Join(l.NodeBin, nodeFile(l.Args[1]))
		return Command{Path: path, Args: append([]string{path}, l.Args[2:]...), Env: Facts{Launch: l}.Env(Reader)}
	}
	arguments := l.Args
	if versionLine(arguments) {
		arguments = []string{arguments[0], "drupack-version"}
	}
	return Command{Path: l.Entry, Args: arguments, Env: Facts{Launch: l}.Env(Reader)}
}

// versionLine reports whether args is a version flag and nothing else.
func versionLine(args []string) bool {
	return len(args) == 2 && (args[1] == "--version" || args[1] == "-v")
}

package main

import (
	"path/filepath"
	"slices"
)

// nodeWords name the programs of a site's bundled Node release.
var nodeWords = []string{"node", "npm", "npx"}

// runtimeArguments turns the reader's words into the runtime's. A site's php-cli,
// the version flags, a Node word where this file carries Node, and the Engine's
// php pass unchanged. Every other line reaches
// launch.php, or the Engine's serve.php, through php-cli, which reads the command
// word itself.
func runtimeArguments(args []string, application string, engine, node bool) []string {
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

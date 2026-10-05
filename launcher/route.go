package main

import "path/filepath"

// runtimeArguments turns the reader's words into the runtime's. The Engine's
// `php` and the version flags reach the runtime, which answers them, and every
// other line reaches serve.php through php-cli.
func runtimeArguments(args []string, application string, engine, node bool) []string {
	if !engine {
		return args
	}
	switch {
	case len(args) > 1 && args[1] == "php":
	case len(args) == 2 && (args[1] == "--version" || args[1] == "-v"):
	default:
		return append([]string{args[0], "php-cli", filepath.Join(application, "serve.php")}, args[1:]...)
	}
	return args
}

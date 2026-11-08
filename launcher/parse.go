package main

import (
	"fmt"
	"slices"
	"strings"
)

// command is a reader's line as the launcher answers it.
type command struct {
	// word is "stop", "help" for a site's `stop --help`, or "" for a line the
	// runtime's PHP reads.
	word string
	// target is stop's Site data or folder, as the reader wrote it.
	target string
}

// option is one option a word takes, as the help text writes it.
type option struct {
	name, value string
}

// stopOptions are the options a site's stop takes. parse_test.go asserts them
// against the help text and docs/cli.md.
var stopOptions = []option{{name: "data-dir", value: "PATH"}}

// defaultDataDir is the Site data of a reader who names none. launch.php's
// DEFAULT_DATA_DIR holds it too while PHP parses the start's options.
const defaultDataDir = "./data"

// parse reads args, os.Args, for the words the launcher answers. lookup reads
// the environment.
func parse(args []string, engine bool, lookup func(string) (string, bool)) (command, error) {
	if len(args) < 2 || args[1] != "stop" {
		return command{}, nil
	}
	if engine {
		return parseFolderStop(args[2:])
	}
	return parseSiteStop(args[2:], lookup)
}

// `start` and `stop` are reserved, so a folder with either name takes ./start or ./stop.
func parseFolderStop(arguments []string) (command, error) {
	if len(arguments) > 1 || len(arguments) == 1 && strings.HasPrefix(arguments[0], "-") {
		return command{}, fmt.Errorf("stop takes a folder alone. Run %s --help.", siteName)
	}
	target := "."
	if len(arguments) == 1 {
		target = arguments[0]
	}
	return command{word: "stop", target: target}, nil
}

// parseSiteStop reads the options in order, so --help answers where it stands.
// stop takes no start option, so none reaches a command that changes no site.
func parseSiteStop(arguments []string, lookup func(string) (string, bool)) (command, error) {
	target := defaultDataDir
	if value, _ := lookup("DRUPACK_DATA_DIR"); value != "" {
		target = value
	}
	for len(arguments) > 0 {
		argument := arguments[0]
		arguments = arguments[1:]
		if argument == "--help" {
			return command{word: "help"}, nil
		}
		name, value, inline := strings.Cut(argument, "=")
		if !slices.ContainsFunc(stopOptions, func(o option) bool { return "--"+o.name == name }) {
			return command{}, fmt.Errorf("Unknown argument: %s", argument)
		}
		if !inline && len(arguments) > 0 {
			value = arguments[0]
			arguments = arguments[1:]
		}
		if value == "" || strings.HasPrefix(value, "--") {
			return command{}, fmt.Errorf("Missing value for %s", name)
		}
		target = value
	}
	return command{word: "stop", target: target}, nil
}

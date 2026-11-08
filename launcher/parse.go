package main

import (
	"fmt"
	"slices"
	"strings"
)

// command is a reader's line as the launcher answers it.
type command struct {
	// word is "stop", "drush", "help" for a site's `stop --help`, or "" for a
	// line the runtime's PHP reads.
	word string
	// target is stop's Site data or folder, as the reader wrote it.
	target string
	// options holds a site's drush options by name, as the reader wrote them.
	// A flag holds "1". data-dir is always present.
	options map[string]string
	// words are drush's own words, after the options a site's drush reads.
	words []string
}

// option is one option a word takes, as the help text writes it.
type option struct {
	name, value string
}

var dataDirOption = option{name: "data-dir", value: "PATH"}

// stopOptions are the options a site's stop takes. parse_test.go asserts them
// against the help text and docs/cli.md.
var stopOptions = []option{dataDirOption}

// siteOptions are the options a site's start takes, and its drush accepts. A
// flag has no value.
var siteOptions = []option{
	dataDirOption,
	{name: "listen", value: "IP:PORT"},
	{name: "static-listen", value: "IP:PORT"},
	{name: "host", value: "HOST"},
	{name: "files-dir", value: "PATH"},
	{name: "database", value: "sqlite|mysql|pgsql"},
	{name: "db-host", value: "HOST"},
	{name: "db-port", value: "PORT"},
	{name: "db-name", value: "NAME"},
	{name: "db-user", value: "NAME"},
	{name: "db-password", value: "PASSWORD"},
	{name: "admin-user", value: "NAME"},
	{name: "admin-password", value: "PASSWORD"},
	{name: "site-name", value: "NAME"},
	{name: "no-browser"},
	{name: "foreground"},
}

// defaultDataDir is the Site data of a reader who names none. launch.php's
// DEFAULT_DATA_DIR holds it too while PHP parses the start's options.
const defaultDataDir = "./data"

// parse reads args, os.Args, for the words the launcher answers. lookup reads
// the environment.
func parse(args []string, engine bool, lookup func(string) (string, bool)) (command, error) {
	if len(args) < 2 {
		return command{}, nil
	}
	switch {
	case args[1] == "stop" && engine:
		return parseFolderStop(args[2:])
	case args[1] == "stop":
		return parseSiteStop(args[2:], lookup)
	case args[1] == "drush" && engine:
		return command{word: "drush", words: args[2:]}, nil
	case args[1] == "drush":
		return parseSiteDrush(args[2:], lookup)
	}
	return command{}, nil
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

// parseSiteDrush reads the start's options in order. The first argument that
// names none of them opens drush's own words, so drush's options pass through.
func parseSiteDrush(arguments []string, lookup func(string) (string, bool)) (command, error) {
	options := map[string]string{dataDirOption.name: defaultDataDir}
	if value, _ := lookup("DRUPACK_DATA_DIR"); value != "" {
		options[dataDirOption.name] = value
	}
	for len(arguments) > 0 {
		name, value, inline := strings.Cut(arguments[0], "=")
		i := slices.IndexFunc(siteOptions, func(o option) bool { return "--"+o.name == name })
		if i < 0 {
			break
		}
		arguments = arguments[1:]
		o := siteOptions[i]
		if o.value == "" {
			options[o.name] = "1"
			continue
		}
		if !inline && len(arguments) > 0 {
			value = arguments[0]
			arguments = arguments[1:]
		}
		if value == "" || strings.HasPrefix(value, "--") {
			return command{}, fmt.Errorf("Missing value for --%s", o.name)
		}
		options[o.name] = value
	}
	return command{word: "drush", options: options, words: arguments}, nil
}

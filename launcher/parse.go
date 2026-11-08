package main

import (
	"fmt"
	"slices"
	"strings"
)

// command is a reader's line as the launcher answers it.
type command struct {
	// word is "stop", "drush", "export", "help", "start", "dr" for the
	// engine's, "node" for a site's Node word, or "" for a line the runtime reads.
	word string
	// target is stop's Site data or folder, or the engine start's folder, as
	// the reader wrote it. An engine start without one holds "".
	target string
	// options holds the options a reader wrote, by name. A flag holds "1".
	// A site's start, drush and export always hold data-dir.
	options map[string]string
	// words are drush's own words, after the options a site's drush or export reads.
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

// engineOptions are the options the engine's start takes. parse_test.go asserts
// them against the usage text and docs/cli.md.
var engineOptions = []option{
	{name: "listen", value: "IP:PORT"},
	{name: "static-listen", value: "IP:PORT"},
	{name: "foreground"},
}

// defaultDataDir is the Site data of a reader who names none.
const defaultDataDir = "./data"

// defaultListen is where the engine serves a reader who names no listener.
const defaultListen = "127.0.0.1:3787"

// parse reads args, os.Args, for the words the launcher answers. lookup reads
// the environment.
func parse(args []string, engine bool, lookup func(string) (string, bool)) (command, error) {
	if len(args) < 2 && engine {
		return parseEngineStart(nil)
	}
	if len(args) < 2 {
		return siteStart(nil, lookup)
	}
	word, rest := args[1], args[2:]
	switch {
	case word == "stop" && engine:
		return parseFolderStop(rest)
	case word == "stop":
		options, _, help, err := scanOptions(rest, stopOptions, lookup, true)
		switch {
		case err != nil:
			return command{}, err
		case help:
			return command{word: "help"}, nil
		}
		return command{word: "stop", target: options[dataDirOption.name]}, nil
	case word == "drush" && engine:
		return command{word: "drush", words: rest}, nil
	case word == "php" || len(args) == 2 && (word == "--version" || word == "-v"):
		return command{}, nil
	case engine && (word == "export" || word == "dr"):
		return command{word: word, words: rest}, nil
	case engine && (word == "--help" || word == "-h"):
		return command{word: "help"}, nil
	case engine && word == "start":
		return parseEngineStart(rest)
	case engine:
		return parseEngineStart(args[1:])
	case word == "php-cli":
		return command{}, nil
	case word == "--help" || word == "-h":
		return command{word: "help"}, nil
	case slices.Contains(nodeWords, word):
		return command{word: "node"}, nil
	case word == "drush" || word == "export":
		options, words, _, err := scanOptions(rest, siteOptions, lookup, false)
		if err != nil {
			return command{}, err
		}
		return command{word: word, options: options, words: words}, nil
	case word == "start":
		return siteStart(rest, lookup)
	case strings.HasPrefix(word, "-"):
		return siteStart(args[1:], lookup)
	}
	return command{}, fmt.Errorf("Unknown command: %s\n\n%s", word, siteHelpText())
}

func siteStart(arguments []string, lookup func(string) (string, bool)) (command, error) {
	options, _, help, err := scanOptions(arguments, siteOptions, lookup, true)
	switch {
	case err != nil:
		return command{}, err
	case help:
		return command{word: "help"}, nil
	}
	return command{word: "start", options: options}, nil
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

// scanOptions reads a site's options from table in order. A strict scan, the
// start's and stop's, refuses any other argument and answers --help; the drush
// and export scan stops at the first argument that names no option and returns
// the rest.
func scanOptions(arguments []string, table []option, lookup func(string) (string, bool), strict bool) (options map[string]string, rest []string, help bool, err error) {
	options = map[string]string{dataDirOption.name: defaultDataDir}
	if value, _ := lookup("DRUPACK_DATA_DIR"); value != "" {
		options[dataDirOption.name] = value
	}
	for len(arguments) > 0 {
		if strict && arguments[0] == "--help" {
			return nil, nil, true, nil
		}
		name, value, inline := strings.Cut(arguments[0], "=")
		i := slices.IndexFunc(table, func(o option) bool { return "--"+o.name == name })
		if i < 0 && strict {
			return nil, nil, false, fmt.Errorf("Unknown argument: %s", arguments[0])
		}
		if i < 0 {
			break
		}
		arguments = arguments[1:]
		o := table[i]
		if o.value == "" {
			options[o.name] = "1"
			continue
		}
		if !inline && len(arguments) > 0 {
			value = arguments[0]
			arguments = arguments[1:]
		}
		if value == "" || strings.HasPrefix(value, "--") {
			return nil, nil, false, fmt.Errorf("Missing value for --%s", o.name)
		}
		options[o.name] = value
	}
	return options, arguments, false, nil
}

// parseEngineStart reads the engine's start: options in any order and one
// folder. A listener option takes the next argument whatever it holds, and
// its value is checked once the start plans it.
func parseEngineStart(arguments []string) (command, error) {
	c := command{word: "start", options: map[string]string{}}
	named := false
	for len(arguments) > 0 {
		argument := arguments[0]
		arguments = arguments[1:]
		i := slices.IndexFunc(engineOptions, func(o option) bool {
			return argument == "--"+o.name || o.value != "" && strings.HasPrefix(argument, "--"+o.name+"=")
		})
		switch {
		case i >= 0 && engineOptions[i].value == "":
			c.options[engineOptions[i].name] = "1"
		case i >= 0:
			o := engineOptions[i]
			value, inline := strings.CutPrefix(argument, "--"+o.name+"=")
			if !inline {
				if len(arguments) == 0 {
					return command{}, fmt.Errorf("--%s takes %s", o.name, o.value)
				}
				value, arguments = arguments[0], arguments[1:]
			}
			c.options[o.name] = value
		case !named && !strings.HasPrefix(argument, "-"):
			c.target, named = argument, true
		default:
			return command{}, fmt.Errorf("Unknown argument %s. Run %s --help.", argument, siteName)
		}
	}
	return c, nil
}

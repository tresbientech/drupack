package main

import (
	"fmt"
	"slices"
	"strings"
)

// command is a reader's line as the launcher answers it.
type command struct {
	// word is "stop", "drush", "export", "help", "start", "trust", "self-update",
	// "clean", "dr" and "composer" for the engine's, "node" for a site's Node word,
	// or "" for a line the runtime reads.
	word string
	// target is stop's Site data or folder, or the engine start's folder, as
	// the reader wrote it. An engine start without one holds "".
	target string
	// options holds the options a reader wrote. A site's start, drush and export
	// always hold DataDir, and the engine's start always holds Listen.
	options options
	// words are drush's own words, after the options a site's drush or export
	// reads, or the words after trust, self-update or clean.
	words []string
}

// options are the options a reader wrote, each empty when left out.
type options struct {
	DataDir, Listen, StaticListen, Host, FilesDir, TrustedProxies string
	Database, DBHost, DBPort, DBName, DBUser, DBPassword          string
	AdminUser, AdminPassword, SiteName                            string
	NoBrowser, Foreground                                         bool
	// AccessLog and ErrorLog are nil for a line without the option, and hold ""
	// for the option written bare.
	AccessLog, ErrorLog *string
}

// option is one option a word takes, as the help text writes it.
type option struct {
	name, value string
	// optional marks a value the reader may leave out, and writes as --name=VALUE alone.
	optional bool
	// set writes the value the reader gave into its field. A flag gets "".
	set func(o *options, value string)
}

var (
	accessLogOption      = option{name: "access-log", value: "FILE", optional: true, set: func(o *options, v string) { o.AccessLog = &v }}
	errorLogOption       = option{name: "error-log", value: "FILE", optional: true, set: func(o *options, v string) { o.ErrorLog = &v }}
	trustedProxiesOption = option{name: "trusted-proxies", value: "CIDR[,CIDR]", set: func(o *options, v string) { o.TrustedProxies = v }}
	listenOption         = option{name: "listen", value: "IP:PORT", set: func(o *options, v string) { o.Listen = v }}
	staticListenOption   = option{name: "static-listen", value: "IP:PORT", set: func(o *options, v string) { o.StaticListen = v }}
	hostOption           = option{name: "host", value: "HOST", set: func(o *options, v string) { o.Host = v }}
	foregroundOption     = option{name: "foreground", set: func(o *options, _ string) { o.Foreground = true }}
)

// optionalValue reads the value of an optional-value option from argument, ""
// for the bare option. following is the argument after it, "" for none.
func optionalValue(o option, argument, following string) (string, error) {
	_, value, inline := strings.Cut(argument, "=")
	switch {
	case inline && value == "":
		return "", fmt.Errorf("Missing value for --%s", o.name)
	case !inline && following != "" && !strings.HasPrefix(following, "-"):
		return "", fmt.Errorf("--%[1]s takes its value as --%[1]s=%[2]s", o.name, o.value)
	}
	return value, nil
}

var dataDirOption = option{name: "data-dir", value: "PATH", set: func(o *options, v string) { o.DataDir = v }}

// stopOptions are the options a site's stop takes. parse_test.go asserts them
// against the help text and docs/cli.md.
var stopOptions = []option{dataDirOption}

// siteOptions are the options a site's start takes, and its drush accepts. A
// flag has no value.
var siteOptions = []option{
	dataDirOption,
	listenOption,
	staticListenOption,
	hostOption,
	{name: "files-dir", value: "PATH", set: func(o *options, v string) { o.FilesDir = v }},
	{name: "database", value: "sqlite|mysql|pgsql", set: func(o *options, v string) { o.Database = v }},
	{name: "db-host", value: "HOST", set: func(o *options, v string) { o.DBHost = v }},
	{name: "db-port", value: "PORT", set: func(o *options, v string) { o.DBPort = v }},
	{name: "db-name", value: "NAME", set: func(o *options, v string) { o.DBName = v }},
	{name: "db-user", value: "NAME", set: func(o *options, v string) { o.DBUser = v }},
	{name: "db-password", value: "PASSWORD", set: func(o *options, v string) { o.DBPassword = v }},
	{name: "admin-user", value: "NAME", set: func(o *options, v string) { o.AdminUser = v }},
	{name: "admin-password", value: "PASSWORD", set: func(o *options, v string) { o.AdminPassword = v }},
	{name: "site-name", value: "NAME", set: func(o *options, v string) { o.SiteName = v }},
	{name: "no-browser", set: func(o *options, _ string) { o.NoBrowser = true }},
	foregroundOption,
	accessLogOption,
	errorLogOption,
	trustedProxiesOption,
}

// engineOptions are the options the engine's start takes. parse_test.go asserts
// them against the usage text and docs/cli.md.
var engineOptions = []option{
	listenOption,
	staticListenOption,
	hostOption,
	foregroundOption,
	accessLogOption,
	errorLogOption,
	trustedProxiesOption,
}

// defaultDataDir is the Site data of a reader who names none.
const defaultDataDir = "./data"

// defaultFolder is the engine's project folder when the reader names none.
const defaultFolder = "."

// loopbackIP is the address a start binds when the reader names no listener.
const loopbackIP = "127.0.0.1"

// defaultListen is where the engine serves a reader who names no listener.
const defaultListen = loopbackIP + ":3787"

// parse reads l.Args for the words the launcher answers.
func parse(l Launch) (command, error) {
	args, engine, lookup := l.Args, l.Engine, l.lookup
	if len(args) < 2 && engine {
		return parseEngineStart(l.Name, nil)
	}
	if len(args) < 2 {
		return siteStart(nil, lookup)
	}
	word, rest := args[1], args[2:]
	switch {
	case word == "trust" || word == "self-update" || word == "clean":
		return command{word: word, words: rest}, nil
	case word == "stop" && engine:
		return parseFolderStop(l.Name, rest)
	case word == "stop":
		read, _, help, err := scanOptions(rest, stopOptions, lookup, true)
		switch {
		case err != nil:
			return command{}, err
		case help:
			return command{word: "help"}, nil
		}
		return command{word: "stop", target: read.DataDir}, nil
	case word == "drush" && engine:
		return command{word: "drush", words: rest}, nil
	case word == "php" || versionLine(args):
		return command{}, nil
	case engine && (word == "export" || word == "dr" || word == "composer"):
		return command{word: word, words: rest}, nil
	case engine && (word == "--help" || word == "-h"):
		return command{word: "help"}, nil
	case engine && word == "start":
		return parseEngineStart(l.Name, rest)
	case engine:
		return parseEngineStart(l.Name, args[1:])
	case word == "php-cli":
		return command{}, nil
	case word == "--help" || word == "-h":
		return command{word: "help"}, nil
	case slices.Contains(nodeWords, word):
		return command{word: "node"}, nil
	case word == "drush" || word == "export":
		read, words, _, err := scanOptions(rest, siteOptions, lookup, false)
		if err != nil {
			return command{}, err
		}
		return command{word: word, options: read, words: words}, nil
	case word == "start":
		return siteStart(rest, lookup)
	case strings.HasPrefix(word, "-"):
		return siteStart(args[1:], lookup)
	}
	return command{}, fmt.Errorf("Unknown command: %s\n\n%s", word, siteHelpText(l.Name))
}

func siteStart(arguments []string, lookup func(string) (string, bool)) (command, error) {
	read, _, help, err := scanOptions(arguments, siteOptions, lookup, true)
	switch {
	case err != nil:
		return command{}, err
	case help:
		return command{word: "help"}, nil
	}
	return command{word: "start", options: read}, nil
}

// `start` and `stop` are reserved, so a folder with either name takes ./start or ./stop.
func parseFolderStop(name string, arguments []string) (command, error) {
	if len(arguments) > 1 || len(arguments) == 1 && strings.HasPrefix(arguments[0], "-") {
		return command{}, fmt.Errorf("stop takes a folder alone. Run %s --help.", name)
	}
	target := defaultFolder
	if len(arguments) == 1 {
		target = arguments[0]
	}
	return command{word: "stop", target: target}, nil
}

// scanOptions reads a site's options from table in order. A strict scan, the
// start's and stop's, refuses any other argument and answers --help; the drush
// and export scan stops at the first argument that names no option and returns
// the rest.
func scanOptions(arguments []string, table []option, lookup func(string) (string, bool), strict bool) (read options, rest []string, help bool, err error) {
	read.DataDir = defaultDataDir
	if value, _ := lookup("DRUPACK_DATA_DIR"); value != "" {
		read.DataDir = value
	}
	for len(arguments) > 0 {
		if strict && arguments[0] == "--help" {
			return options{}, nil, true, nil
		}
		name, value, inline := strings.Cut(arguments[0], "=")
		i := slices.IndexFunc(table, func(o option) bool { return "--"+o.name == name })
		if i < 0 && strict {
			return options{}, nil, false, fmt.Errorf("Unknown argument: %s", arguments[0])
		}
		if i < 0 {
			break
		}
		argument := arguments[0]
		arguments = arguments[1:]
		o := table[i]
		if o.value == "" {
			o.set(&read, "")
			continue
		}
		if o.optional {
			// Drush's own words follow its options, so only a strict scan reads the next argument.
			following := ""
			if strict && len(arguments) > 0 {
				following = arguments[0]
			}
			written, err := optionalValue(o, argument, following)
			if err != nil {
				return options{}, nil, false, err
			}
			o.set(&read, written)
			continue
		}
		if !inline && len(arguments) > 0 {
			value = arguments[0]
			arguments = arguments[1:]
		}
		if value == "" || strings.HasPrefix(value, "--") {
			return options{}, nil, false, fmt.Errorf("Missing value for --%s", o.name)
		}
		o.set(&read, value)
	}
	return read, arguments, false, nil
}

// parseEngineStart reads the engine's start: options in any order and one
// folder. A listener option takes the next argument whatever it holds, and
// its value is checked once the start plans it.
func parseEngineStart(name string, arguments []string) (command, error) {
	c := command{word: "start", options: options{Listen: defaultListen}}
	named := false
	for len(arguments) > 0 {
		argument := arguments[0]
		arguments = arguments[1:]
		i := slices.IndexFunc(engineOptions, func(o option) bool {
			return argument == "--"+o.name || o.value != "" && strings.HasPrefix(argument, "--"+o.name+"=")
		})
		switch {
		case i >= 0 && engineOptions[i].value == "":
			engineOptions[i].set(&c.options, "")
		case i >= 0 && engineOptions[i].optional:
			following := ""
			if len(arguments) > 0 {
				following = arguments[0]
			}
			value, err := optionalValue(engineOptions[i], argument, following)
			if err != nil {
				return command{}, err
			}
			engineOptions[i].set(&c.options, value)
		case i >= 0:
			o := engineOptions[i]
			value, inline := strings.CutPrefix(argument, "--"+o.name+"=")
			if !inline {
				if len(arguments) == 0 {
					return command{}, fmt.Errorf("--%s takes %s", o.name, o.value)
				}
				value, arguments = arguments[0], arguments[1:]
			}
			o.set(&c.options, value)
		case !named && !strings.HasPrefix(argument, "-"):
			c.target, named = argument, true
		default:
			return command{}, fmt.Errorf("Unknown argument %s. Run %s --help.", argument, name)
		}
	}
	return c, nil
}

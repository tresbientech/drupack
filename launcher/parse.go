package main

import (
	"errors"
	"fmt"
	"slices"
	"strings"
)

// command is a reader's line as the launcher answers it.
type command struct {
	// word is "stop", "status", "restart", "drush", "export", "help", "start", "trust", "self-update",
	// "clean", "dr" and "composer" for the engine's, "node" for a site's Node word,
	// or "" for a line the runtime reads.
	word string
	// target is stop's, status's or restart's Site data or folder, or the engine start's folder, as
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
	DataDir, Listen, StaticListen, Host, TrustedProxies  string
	Database, DBHost, DBPort, DBName, DBUser, DBPassword string
	AdminUser, AdminPassword, SiteName                   string
	NoBrowser, Foreground, Yes                           bool
	// AccessLog and ErrorLog are nil for a line without the option, and hold ""
	// for the option written bare.
	AccessLog, ErrorLog *string
}

// option is one option a word takes, as the help text writes it.
type option struct {
	name, value string
	// optional marks a value the reader may leave out, and writes as --name=VALUE alone.
	optional bool
	// secret marks a value start.json never records.
	secret bool
	// help is the option's text in the help. A newline starts a line under it.
	help string
	// set writes the value the reader gave into its field. A flag gets "".
	set func(o *options, value string)
}

var (
	accessLogOption = option{
		name: "access-log", value: "FILE", optional: true,
		help: "Log each request to FILE, or bare to access.log\nin the logs folder",
		set:  func(o *options, v string) { o.AccessLog = &v },
	}
	errorLogOption = option{
		name: "error-log", value: "FILE", optional: true,
		help: "Log PHP's and Caddy's warnings and errors to\nFILE, or bare to error.log in the logs folder",
		set:  func(o *options, v string) { o.ErrorLog = &v },
	}
	trustedProxiesOption = option{
		name: "trusted-proxies", value: "CIDR[,CIDR]",
		help: "Proxies whose forwarded header names the\nclient, none by default",
		set:  func(o *options, v string) { o.TrustedProxies = v },
	}
	listenOption = option{
		name: "listen", value: "IP:PORT",
		help: "Listener, " + loopbackIP + " on the port drupack.yml\nnames by default",
		set:  func(o *options, v string) { o.Listen = v },
	}
	staticListenOption = option{
		name: "static-listen", value: "IP:PORT",
		help: "Static listener of a static export, which\nexport also reads, on the listener's next port\nby default",
		set:  func(o *options, v string) { o.StaticListen = v },
	}
	hostOption = option{
		name: "host", value: "HOST",
		help: "Request host, the Site host when it resolves to\n" + loopbackIP + ", localhost otherwise",
		set:  func(o *options, v string) { o.Host = v },
	}
	foregroundOption = option{
		name: "foreground",
		help: "Serve in this terminal until a signal stops it",
		set:  func(o *options, _ string) { o.Foreground = true },
	}
)

// label is the option as the help writes it.
func (o option) label() string {
	switch {
	case o.value == "":
		return "--" + o.name
	case o.optional:
		return "--" + o.name + "[=" + o.value + "]"
	}
	return "--" + o.name + " " + o.value
}

// optionGroup is one titled block of the options a help lists.
type optionGroup struct {
	title   string
	options []option
}

// optionsOf lists the options of groups, in order.
func optionsOf(groups []optionGroup) []option {
	var all []option
	for _, g := range groups {
		all = append(all, g.options...)
	}
	return all
}

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

var dataDirOption = option{
	name: "data-dir", value: "PATH",
	help: "Site data directory, " + defaultDataDir + " by default",
	set:  func(o *options, v string) { o.DataDir = v },
}

// stopOptions are the options a site's stop takes. parse_test.go asserts them
// against docs/cli.md.
var stopOptions = []option{dataDirOption}

// siteGroups are the options a site's start takes, as the help groups them.
// drush and export take the first group. parse_test.go asserts each title
// against the Applies column of docs/cli.md. A flag has no value.
var siteGroups = []optionGroup{
	{"Where the site lives, for start and drush:", []option{
		dataDirOption,
		listenOption,
		staticListenOption,
		hostOption,
	}},
	{"Start options:", []option{
		foregroundOption,
		{name: "no-browser", help: "Do not open a browser", set: func(o *options, _ string) { o.NoBrowser = true }},
		accessLogOption,
		errorLogOption,
		trustedProxiesOption,
	}},
	{"Database options, for every start:", []option{
		{name: "database", value: "sqlite|mysql|pgsql", help: "Database backend, sqlite by default", set: func(o *options, v string) { o.Database = v }},
		{name: "db-host", value: "HOST", help: "Database server, for mysql and pgsql", set: func(o *options, v string) { o.DBHost = v }},
		{name: "db-port", value: "PORT", help: "Database port, 3306 for mysql, 5432 for pgsql", set: func(o *options, v string) { o.DBPort = v }},
		{name: "db-name", value: "NAME", help: "Database name, for mysql and pgsql", set: func(o *options, v string) { o.DBName = v }},
		{name: "db-user", value: "NAME", help: "Database user, for mysql and pgsql", set: func(o *options, v string) { o.DBUser = v }},
		{name: "db-password", value: "PASSWORD", secret: true, help: "Database password, for mysql and pgsql", set: func(o *options, v string) { o.DBPassword = v }},
	}},
	{"First start options:", []option{
		{name: "admin-user", value: "NAME", help: "Administrator account, admin by default", set: func(o *options, v string) { o.AdminUser = v }},
		{name: "admin-password", value: "PASSWORD", secret: true, help: "Administrator password, generated by default", set: func(o *options, v string) { o.AdminPassword = v }},
		{name: "site-name", value: "NAME", help: "Site name, the packaged site's name by default", set: func(o *options, v string) { o.SiteName = v }},
	}},
}

// siteOptions are the options of siteGroups, which the parser reads.
var siteOptions = optionsOf(siteGroups)

// drushOptions are the options drush and export act on: the first group of siteGroups.
var drushOptions = siteGroups[0].options

// engineGroups are the options the engine's start takes, as the help groups
// them. The engine's --listen, --static-listen and --host read otherwise than a
// site's.
var engineGroups = []optionGroup{
	{"Where the project is served:", []option{
		withHelp(listenOption, "Listener, "+defaultListen+" by default"),
		withHelp(staticListenOption, "Static listener, for a project that sets\nextra.drupack.static, the next port by default"),
		withHelp(hostOption, "One more host a loopback listener accepts, such\nas the name a proxy on this machine forwards"),
	}},
	{"Start options:", []option{
		foregroundOption,
		{name: "yes", help: "Run composer install in a fresh clone, without\nasking", set: func(o *options, _ string) { o.Yes = true }},
		accessLogOption,
		errorLogOption,
		trustedProxiesOption,
	}},
}

// engineOptions are the options of engineGroups, which the parser reads.
var engineOptions = optionsOf(engineGroups)

// withHelp is o with help as its help line.
func withHelp(o option, help string) option {
	o.help = help
	return o
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
		return parseEngineStart(l.Invoked, nil)
	}
	if len(args) < 2 {
		return siteStart(nil, lookup)
	}
	word, rest := args[1], args[2:]
	switch {
	case word == "trust" || word == "self-update" || word == "clean":
		return command{word: word, words: rest}, nil
	case (word == "stop" || word == "status" || word == "restart") && engine:
		return parseFolderWord(l.Invoked, word, rest)
	case word == "stop" || word == "status" || word == "restart":
		read, _, help, err := scanOptions(rest, stopOptions, lookup, true)
		switch {
		case err != nil:
			return command{}, err
		case help:
			return command{word: "help"}, nil
		}
		return command{word: word, target: read.DataDir}, nil
	case word == "drush" && engine:
		return command{word: "drush", words: rest}, nil
	case word == "php" || versionLine(args):
		return command{}, nil
	case engine && (word == "export" || word == "dr" || word == "composer"):
		return command{word: word, words: rest}, nil
	case engine && (word == "--help" || word == "-h"):
		return command{word: "help"}, nil
	case engine && word == "start":
		return parseEngineStart(l.Invoked, rest)
	case engine && slices.Contains(reservedWords, word):
		return command{}, fmt.Errorf("%[1]s is not a command. A folder named %[1]s takes ./%[1]s.", word)
	case engine:
		return parseEngineStart(l.Invoked, args[1:])
	case word == "php-cli":
		return command{}, nil
	case word == "--help" || word == "-h":
		return command{word: "help"}, nil
	case slices.Contains(nodeWords, word):
		return command{word: "node"}, nil
	case word == "drush" || word == "export":
		read, words, _, err := scanOptions(rest, drushOptions, lookup, false)
		if err != nil {
			return command{}, err
		}
		// The scan stops at a start's option, which Drush would otherwise receive. Its
		// name alone is printed, since a secret's value can follow the =.
		if len(words) > 0 {
			name, _, _ := strings.Cut(words[0], "=")
			if slices.ContainsFunc(siteOptions, func(o option) bool { return "--"+o.name == name }) {
				return command{}, fmt.Errorf("Unknown argument: %s", name)
			}
		}
		return command{word: word, options: read, words: words}, nil
	case word == "start":
		return siteStart(rest, lookup)
	case strings.HasPrefix(word, "-"):
		return siteStart(args[1:], lookup)
	}
	return command{}, unknownCommand(word, l.Invoked)
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

// reservedWords are engine words with no command yet. A folder with one of
// these names takes ./NAME, as one named after a command does.
var reservedWords = []string{"logs"}

// parseFolderWord reads stop's, status's or restart's one folder.
func parseFolderWord(invoked, word string, arguments []string) (command, error) {
	if len(arguments) > 1 || len(arguments) == 1 && strings.HasPrefix(arguments[0], "-") {
		return command{}, fmt.Errorf("%s takes a folder alone. Run %s --help.", word, invoked)
	}
	target := defaultFolder
	if len(arguments) == 1 {
		target = arguments[0]
	}
	return command{word: word, target: target}, nil
}

// withoutOptions are a start's words without the options of table that drop
// picks, and their values.
func withoutOptions(words []string, table []option, drop func(option) bool) []string {
	var kept []string
	for i := 0; i < len(words); i++ {
		name, _, inline := strings.Cut(words[i], "=")
		dropped := slices.ContainsFunc(table, func(o option) bool { return drop(o) && "--"+o.name == name })
		switch {
		case !dropped:
			kept = append(kept, words[i])
		case !inline:
			i++
		}
	}
	return kept
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
		// A restart replays the words of a start that may have named this option.
		if name == "--files-dir" {
			return options{}, nil, false, errors.New("--files-dir is gone. Public files live in files in Site data, which may be a link to another folder. Remove the option, and stop a running site before starting it again.")
		}
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
func parseEngineStart(invoked string, arguments []string) (command, error) {
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
			return command{}, fmt.Errorf("Unknown argument %s. Run %s --help.", argument, invoked)
		}
	}
	return c, nil
}

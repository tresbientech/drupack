package main

import (
	"os"
	"path/filepath"
	"reflect"
	"regexp"
	"slices"
	"strings"
	"testing"
)

func TestParse(t *testing.T) {
	packedAs(t, "fixture-site", "dev", false)
	unset := map[string]string{}
	for _, row := range []struct {
		name   string
		args   []string
		engine bool
		env    map[string]string
		want   command
		err    string
	}{
		{"a bare line is a start on the default Site data", []string{"SITE"}, false, unset, command{word: "start", options: map[string]string{"data-dir": "./data"}}, ""},
		{"a start reads its options", []string{"SITE", "start", "--data-dir", "x"}, false, unset, command{word: "start", options: map[string]string{"data-dir": "x"}}, ""},
		{"a line opening with an option is a start", []string{"SITE", "--listen", "127.0.0.1:9000", "--foreground"}, false, unset, command{word: "start", options: map[string]string{"data-dir": "./data", "listen": "127.0.0.1:9000", "foreground": "1"}}, ""},
		{"stop after an option is a start's unknown argument", []string{"SITE", "--data-dir", "x", "stop"}, false, unset, command{}, "Unknown argument: stop"},
		{"a start refuses an unknown option", []string{"SITE", "start", "--frobnicate"}, false, unset, command{}, "Unknown argument: --frobnicate"},
		{"a start refuses a stray word", []string{"SITE", "extra"}, false, unset, command{}, "Unknown command: extra\n\n" + siteHelpText()},
		{"a start refuses an option with no value", []string{"SITE", "--listen"}, false, unset, command{}, "Missing value for --listen"},
		{"a start reads -v as an option", []string{"SITE", "-v", "x"}, false, unset, command{}, "Unknown argument: -v"},
		{"a start answers --help after its options", []string{"SITE", "--data-dir", "x", "--help"}, false, unset, command{word: "help"}, ""},
		{"a start answers --help after start", []string{"SITE", "start", "--help"}, false, unset, command{word: "help"}, ""},
		{"a start answers -h as the first word", []string{"SITE", "-h", "--bogus"}, false, unset, command{word: "help"}, ""},
		{"export hands --help to Drush", []string{"SITE", "export", "--help"}, false, unset, command{word: "export", options: map[string]string{"data-dir": "./data"}, words: []string{"--help"}}, ""},
		{"export reads the start's options", []string{"SITE", "export", "--data-dir", "d", "--path-count=2"}, false, unset, command{word: "export", options: map[string]string{"data-dir": "d"}, words: []string{"--path-count=2"}}, ""},
		{"export refuses an option with no value", []string{"SITE", "export", "--data-dir"}, false, unset, command{}, "Missing value for --data-dir"},
		{"php goes to the runtime", []string{"SITE", "php", "-r", "1;"}, false, unset, command{}, ""},
		{"php-cli goes to the runtime", []string{"SITE", "php-cli", "x.php"}, false, unset, command{}, ""},
		{"the version flags go to the runtime", []string{"SITE", "--version"}, false, unset, command{}, ""},
		{"a Node word is a Node word", []string{"SITE", "npm", "ci"}, false, unset, command{word: "node"}, ""},
		{"the engine starts with no word", []string{"drupack"}, true, unset, command{word: "start", options: map[string]string{}}, ""},
		{"the engine starts a folder", []string{"drupack", "project"}, true, unset,
			command{word: "start", target: "project", options: map[string]string{}}, ""},
		{"the engine reads start and its options", []string{"drupack", "start", "--listen", "[::1]:9", "--static-listen=127.0.0.1:10", "--foreground", "project"}, true, unset,
			command{word: "start", target: "project", options: map[string]string{"listen": "[::1]:9", "static-listen": "127.0.0.1:10", "foreground": "1"}}, ""},
		{"the engine takes a listener option's next word whatever it holds", []string{"drupack", "--listen", "--foreground"}, true, unset,
			command{word: "start", options: map[string]string{"listen": "--foreground"}}, ""},
		{"the engine refuses a listener with no word", []string{"drupack", "--static-listen"}, true, unset, command{}, "--static-listen takes IP:PORT"},
		{"a start reads a bare --access-log", []string{"SITE", "--access-log", "--foreground"}, false, unset, command{word: "start", options: map[string]string{"data-dir": "./data", "access-log": "", "foreground": "1"}}, ""},
		{"a start reads the file of --access-log", []string{"SITE", "--access-log=a.log"}, false, unset, command{word: "start", options: map[string]string{"data-dir": "./data", "access-log": "a.log"}}, ""},
		{"a start refuses a file after --access-log", []string{"SITE", "--access-log", "a.log"}, false, unset, command{}, "--access-log takes its value as --access-log=FILE"},
		{"a start refuses an empty --access-log=", []string{"SITE", "--access-log="}, false, unset, command{}, "Missing value for --access-log"},
		{"drush keeps the word after --access-log", []string{"SITE", "drush", "--access-log", "status"}, false, unset, command{word: "drush", options: map[string]string{"data-dir": "./data", "access-log": ""}, words: []string{"status"}}, ""},
		{"the engine reads --access-log after its folder", []string{"drupack", "project", "--access-log"}, true, unset,
			command{word: "start", target: "project", options: map[string]string{"access-log": ""}}, ""},
		{"the engine reads the file of --access-log", []string{"drupack", "--access-log=a.log", "project"}, true, unset,
			command{word: "start", target: "project", options: map[string]string{"access-log": "a.log"}}, ""},
		{"the engine reads both log options", []string{"drupack", "--error-log=e.log", "--access-log"}, true, unset,
			command{word: "start", options: map[string]string{"access-log": "", "error-log": "e.log"}}, ""},
		{"a start refuses a file after --error-log", []string{"SITE", "--error-log", "e.log"}, false, unset, command{}, "--error-log takes its value as --error-log=FILE"},
		{"the engine refuses a word after --access-log", []string{"drupack", "--access-log", "a.log"}, true, unset, command{}, "--access-log takes its value as --access-log=FILE"},
		{"the engine refuses a second folder", []string{"drupack", "a", "b"}, true, unset, command{}, "Unknown argument b. Run fixture-site --help."},
		{"the engine refuses an option", []string{"drupack", "start", "--help"}, true, unset, command{}, "Unknown argument --help. Run fixture-site --help."},
		{"the engine answers --help first", []string{"drupack", "--help"}, true, unset, command{word: "help"}, ""},
		{"the engine answers -h first", []string{"drupack", "-h"}, true, unset, command{word: "help"}, ""},
		{"the engine's export", []string{"drupack", "export", "--uri", "u"}, true, unset, command{word: "export", words: []string{"--uri", "u"}}, ""},
		{"the engine's dr", []string{"drupack", "dr", "x"}, true, unset, command{word: "dr", words: []string{"x"}}, ""},
		{"the engine's php goes to the runtime", []string{"drupack", "php", "-r", "1;"}, true, unset, command{}, ""},
		{"the engine reads php-cli as a folder", []string{"drupack", "php-cli"}, true, unset,
			command{word: "start", target: "php-cli", options: map[string]string{}}, ""},
		{"stop takes ./data by default", []string{"SITE", "stop"}, false, unset, command{word: "stop", target: "./data"}, ""},
		{"DRUPACK_DATA_DIR names the Site data", []string{"SITE", "stop"}, false, map[string]string{"DRUPACK_DATA_DIR": "env"}, command{word: "stop", target: "env"}, ""},
		{"an empty DRUPACK_DATA_DIR reads as unset", []string{"SITE", "stop"}, false, map[string]string{"DRUPACK_DATA_DIR": ""}, command{word: "stop", target: "./data"}, ""},
		{"--data-dir overrides the environment", []string{"SITE", "stop", "--data-dir", "site"}, false, map[string]string{"DRUPACK_DATA_DIR": "env"}, command{word: "stop", target: "site"}, ""},
		{"--data-dir=PATH", []string{"SITE", "stop", "--data-dir=site"}, false, unset, command{word: "stop", target: "site"}, ""},
		{"the last --data-dir wins", []string{"SITE", "stop", "--data-dir", "a", "--data-dir=b"}, false, unset, command{word: "stop", target: "b"}, ""},
		{"--data-dir needs a value", []string{"SITE", "stop", "--data-dir"}, false, unset, command{}, "Missing value for --data-dir"},
		{"--data-dir= needs a value", []string{"SITE", "stop", "--data-dir="}, false, unset, command{}, "Missing value for --data-dir"},
		{"an option is no value", []string{"SITE", "stop", "--data-dir", "--help"}, false, unset, command{}, "Missing value for --data-dir"},
		{"a start option is refused", []string{"SITE", "stop", "--listen", "127.0.0.1:1"}, false, unset, command{}, "Unknown argument: --listen"},
		{"--help prints the help", []string{"SITE", "stop", "--help"}, false, unset, command{word: "help"}, ""},
		{"--help after a value prints the help", []string{"SITE", "stop", "--data-dir", "a", "--help", "--bogus"}, false, unset, command{word: "help"}, ""},
		{"--help after a refused argument is not reached", []string{"SITE", "stop", "--bogus", "--help"}, false, unset, command{}, "Unknown argument: --bogus"},
		{"the engine stops the working directory by default", []string{"drupack", "stop"}, true, unset, command{word: "stop", target: "."}, ""},
		{"the engine stops the folder named", []string{"drupack", "stop", "project"}, true, unset, command{word: "stop", target: "project"}, ""},
		{"the engine refuses two folders", []string{"drupack", "stop", "a", "b"}, true, unset, command{}, "stop takes a folder alone. Run fixture-site --help."},
		{"the engine refuses an option", []string{"drupack", "stop", "--help"}, true, unset, command{}, "stop takes a folder alone. Run fixture-site --help."},
		{"drush goes to the launcher", []string{"SITE", "drush", "status"}, false, unset,
			command{word: "drush", options: map[string]string{"data-dir": "./data"}, words: []string{"status"}}, ""},
		{"drush takes no words", []string{"SITE", "drush"}, false, unset,
			command{word: "drush", options: map[string]string{"data-dir": "./data"}, words: []string{}}, ""},
		{"drush reads the start's options first", []string{"SITE", "drush", "--data-dir=d", "--listen", "[::1]:9", "--no-browser", "user:login", "--no-browser"}, false, unset,
			command{word: "drush", options: map[string]string{"data-dir": "d", "listen": "[::1]:9", "no-browser": "1"}, words: []string{"user:login", "--no-browser"}}, ""},
		{"drush options after the first word stay Drush's", []string{"SITE", "drush", "status", "--data-dir", "x"}, false, unset,
			command{word: "drush", options: map[string]string{"data-dir": "./data"}, words: []string{"status", "--data-dir", "x"}}, ""},
		{"drush hands --help to Drush", []string{"SITE", "drush", "--data-dir", "d", "--help"}, false, unset,
			command{word: "drush", options: map[string]string{"data-dir": "d"}, words: []string{"--help"}}, ""},
		{"drush reads DRUPACK_DATA_DIR", []string{"SITE", "drush", "status"}, false, map[string]string{"DRUPACK_DATA_DIR": "env"},
			command{word: "drush", options: map[string]string{"data-dir": "env"}, words: []string{"status"}}, ""},
		{"drush refuses an option with no value", []string{"SITE", "drush", "--host"}, false, unset, command{}, "Missing value for --host"},
		{"drush reads an option as no value", []string{"SITE", "drush", "--files-dir", "--listen", "x"}, false, unset, command{}, "Missing value for --files-dir"},
		{"the engine passes every word to Drush", []string{"drupack", "drush", "--data-dir", "x", "status"}, true, unset,
			command{word: "drush", words: []string{"--data-dir", "x", "status"}}, ""},
		{"the engine takes no Site data", []string{"drupack", "stop", "--data-dir", "x"}, true, unset, command{}, "stop takes a folder alone. Run fixture-site --help."},
	} {
		lookup := func(name string) (string, bool) { value, set := row.env[name]; return value, set }
		got, err := parse(row.args, row.engine, lookup)
		message := ""
		if err != nil {
			message = err.Error()
		}
		if !reflect.DeepEqual(got, row.want) || message != row.err {
			t.Errorf("%s: parse(%q) = %+v, %q, want %+v, %q", row.name, row.args, got, message, row.want, row.err)
		}
	}
}

// usage renders the stop usage line from the options the parser reads.
func siteStopUsage() string {
	usage := "stop"
	for _, o := range stopOptions {
		usage += " [--" + o.name + " " + o.value + "]"
	}
	return usage
}

// optionNames lists the options text names, sorted and without repeats. help and
// version stand beside the options and set no key.
func optionNames(text string) []string {
	var names []string
	for _, match := range regexp.MustCompile(`--([a-z][a-z-]*)`).FindAllStringSubmatch(text, -1) {
		if name := match[1]; name != "help" && name != "version" {
			names = append(names, name)
		}
	}
	slices.Sort(names)
	return slices.Compact(names)
}

func parserOptions(tables ...[]option) []string {
	var names []string
	for _, table := range tables {
		for _, o := range table {
			names = append(names, o.name)
		}
	}
	slices.Sort(names)
	return slices.Compact(names)
}

// The help texts, the parser's tables and docs/cli.md name the same options and
// commands.
func TestHelpMatchesTheParserAndTheReference(t *testing.T) {
	packedAs(t, "fixture-site", "dev", false)
	reference := read(t, filepath.Join("..", "docs", "cli.md"))
	options := regexp.MustCompile("(?m)^\\| `(--[a-z-]+)` \\|").FindAllStringSubmatch(reference, -1)
	var table strings.Builder
	for _, row := range options {
		table.WriteString(row[1] + " ")
	}
	block := regexp.MustCompile(`(?s)\nOptions:\n(.*?)\nCommands:\n`).FindStringSubmatch(siteHelp)
	if block == nil {
		t.Fatal("the site help has no Options: block ending at Commands:")
	}
	want := parserOptions(siteOptions)
	if got := optionNames(block[1]); !slices.Equal(got, want) {
		t.Errorf("the site help names %v, the parser reads %v", got, want)
	}
	if got := optionNames(table.String()); !slices.Equal(got, want) {
		t.Errorf("docs/cli.md names %v, the parser reads %v", got, want)
	}
	site := "stop " + "[--data-dir PATH]"
	if siteStopUsage() != site {
		t.Errorf("stop reads %q, the test expects %q", siteStopUsage(), site)
	}
	for _, row := range []struct{ file, text, usage string }{
		{"the site help", siteHelp, "%[1]s " + siteStopUsage() + "\n"},
		{"docs/cli.md", reference, "SITE " + siteStopUsage() + "\n"},
		{"the engine usage", engineUsage, "%[1]s stop [DIR]\n"},
		{"docs/cli.md", reference, "drupack stop [DIR]\n"},
	} {
		if !strings.Contains(row.text, row.usage) {
			t.Errorf("%s does not hold %q", row.file, row.usage)
		}
	}
}

func TestEngineUsageMatchesTheParserAndTheReference(t *testing.T) {
	reference := read(t, filepath.Join("..", "docs", "cli.md"))
	line := "Usage: %[1]s [start] [DIR]"
	for _, o := range engineOptions {
		line += " [--" + o.name
		switch {
		case o.optional:
			line += "[=" + o.value + "]"
		case o.value != "":
			line += " " + o.value
		}
		line += "]"
	}
	if !strings.Contains(engineUsage, line+"\n") {
		t.Errorf("the engine usage does not open with %q", line)
	}
	section := reference[strings.Index(reference, "## The engine executable"):]
	commands := regexp.MustCompile(`(?m)^  ([a-z-]+) {2,}`).FindAllStringSubmatch(engineUsage, -1)
	var names []string
	for _, command := range commands {
		names = append(names, command[1])
		if !strings.Contains(section, "drupack "+command[1]) {
			t.Errorf("docs/cli.md's engine section omits %s", command[1])
		}
	}
	if want := []string{"start", "stop", "drush", "dr", "export", "php", "clean", "self-update", "trust"}; !slices.Equal(names, want) {
		t.Errorf("the engine usage lists %v, want %v", names, want)
	}
	for _, name := range parserOptions(engineOptions) {
		if !strings.Contains(section, "--"+name) {
			t.Errorf("docs/cli.md's engine section omits --%s", name)
		}
	}
}

func read(t *testing.T, path string) string {
	t.Helper()
	content, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return string(content)
}

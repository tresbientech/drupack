package main

import (
	"maps"
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
	some := func(value string) *string { return &value }
	for _, row := range []struct {
		name   string
		args   []string
		engine bool
		env    map[string]string
		want   command
		err    string
	}{
		{"a bare line is a start on the default Site data", []string{"SITE"}, false, unset, command{word: "start", options: options{DataDir: "./data"}}, ""},
		{"a start reads its options", []string{"SITE", "start", "--data-dir", "x"}, false, unset, command{word: "start", options: options{DataDir: "x"}}, ""},
		{"a line opening with an option is a start", []string{"SITE", "--listen", "127.0.0.1:9000", "--foreground"}, false, unset, command{word: "start", options: options{DataDir: "./data", Listen: "127.0.0.1:9000", Foreground: true}}, ""},
		{"stop after an option is a start's unknown argument", []string{"SITE", "--data-dir", "x", "stop"}, false, unset, command{}, "Unknown argument: stop"},
		{"a start refuses an unknown option", []string{"SITE", "start", "--frobnicate"}, false, unset, command{}, "Unknown argument: --frobnicate"},
		{"a start refuses a stray word", []string{"SITE", "extra"}, false, unset, command{}, "Unknown command: extra. Run ./fixture-site --help."},
		{"a start refuses an option with no value", []string{"SITE", "--listen"}, false, unset, command{}, "Missing value for --listen"},
		{"a start reads -v as an option", []string{"SITE", "-v", "x"}, false, unset, command{}, "Unknown argument: -v"},
		{"a start answers --help after its options", []string{"SITE", "--data-dir", "x", "--help"}, false, unset, command{word: "help"}, ""},
		{"a start answers --help after start", []string{"SITE", "start", "--help"}, false, unset, command{word: "help"}, ""},
		{"a start answers -h as the first word", []string{"SITE", "-h", "--bogus"}, false, unset, command{word: "help"}, ""},
		{"export hands --help to Drush", []string{"SITE", "export", "--help"}, false, unset, command{word: "export", options: options{DataDir: "./data"}, words: []string{"--help"}}, ""},
		{"export reads the start's options", []string{"SITE", "export", "--data-dir", "d", "--path-count=2"}, false, unset, command{word: "export", options: options{DataDir: "d"}, words: []string{"--path-count=2"}}, ""},
		{"export refuses an option with no value", []string{"SITE", "export", "--data-dir"}, false, unset, command{}, "Missing value for --data-dir"},
		{"php goes to the runtime", []string{"SITE", "php", "-r", "1;"}, false, unset, command{}, ""},
		{"php-cli goes to the runtime", []string{"SITE", "php-cli", "x.php"}, false, unset, command{}, ""},
		{"the version flags go to the runtime", []string{"SITE", "--version"}, false, unset, command{}, ""},
		{"a Node word is a Node word", []string{"SITE", "npm", "ci"}, false, unset, command{word: "node"}, ""},
		{"the engine starts with no word", []string{"drupack"}, true, unset, command{word: "start", options: options{Listen: defaultListen}}, ""},
		{"the engine starts a folder", []string{"drupack", "project"}, true, unset,
			command{word: "start", target: "project", options: options{Listen: defaultListen}}, ""},
		{"the engine reads start and its options", []string{"drupack", "start", "--listen", "[::1]:9", "--static-listen=127.0.0.1:10", "--foreground", "project"}, true, unset,
			command{word: "start", target: "project", options: options{Listen: "[::1]:9", StaticListen: "127.0.0.1:10", Foreground: true}}, ""},
		{"the engine takes a listener option's next word whatever it holds", []string{"drupack", "--listen", "--foreground"}, true, unset,
			command{word: "start", options: options{Listen: "--foreground"}}, ""},
		{"the engine reads --yes", []string{"drupack", "--yes"}, true, unset, command{word: "start", options: options{Listen: defaultListen, Yes: true}}, ""},
		{"a site start refuses --yes", []string{"SITE", "--yes"}, false, unset, command{}, "Unknown argument: --yes"},
		{"the engine refuses a listener with no word", []string{"drupack", "--static-listen"}, true, unset, command{}, "--static-listen takes IP:PORT"},
		{"a start reads a bare --access-log", []string{"SITE", "--access-log", "--foreground"}, false, unset, command{word: "start", options: options{DataDir: "./data", AccessLog: some(""), Foreground: true}}, ""},
		{"a start reads the file of --access-log", []string{"SITE", "--access-log=a.log"}, false, unset, command{word: "start", options: options{DataDir: "./data", AccessLog: some("a.log")}}, ""},
		{"a start refuses a file after --access-log", []string{"SITE", "--access-log", "a.log"}, false, unset, command{}, "--access-log takes its value as --access-log=FILE"},
		{"a start refuses an empty --access-log=", []string{"SITE", "--access-log="}, false, unset, command{}, "Missing value for --access-log"},
		{"drush refuses --access-log", []string{"SITE", "drush", "--access-log", "status"}, false, unset, command{}, "Unknown argument: --access-log"},
		{"drush refuses a first start's secret and prints its name alone", []string{"SITE", "drush", "--admin-password=x", "user:login"}, false, unset, command{}, "Unknown argument: --admin-password"},
		{"drush refuses a database option", []string{"SITE", "drush", "--database", "bogus", "status"}, false, unset, command{}, "Unknown argument: --database"},
		{"export refuses a start option", []string{"SITE", "export", "--foreground"}, false, unset, command{}, "Unknown argument: --foreground"},
		{"drush reads its own options", []string{"SITE", "drush", "--data-dir", "d", "--files-dir=f", "status"}, false, unset, command{word: "drush", options: options{DataDir: "d", FilesDir: "f"}, words: []string{"status"}}, ""},
		{"drush hands a start option after the command to Drush", []string{"SITE", "drush", "sql:dump", "--database=x"}, false, unset, command{word: "drush", options: options{DataDir: "./data"}, words: []string{"sql:dump", "--database=x"}}, ""},
		{"the engine reads --access-log after its folder", []string{"drupack", "project", "--access-log"}, true, unset,
			command{word: "start", target: "project", options: options{Listen: defaultListen, AccessLog: some("")}}, ""},
		{"the engine reads the file of --access-log", []string{"drupack", "--access-log=a.log", "project"}, true, unset,
			command{word: "start", target: "project", options: options{Listen: defaultListen, AccessLog: some("a.log")}}, ""},
		{"the engine reads both log options", []string{"drupack", "--error-log=e.log", "--access-log"}, true, unset,
			command{word: "start", options: options{Listen: defaultListen, AccessLog: some(""), ErrorLog: some("e.log")}}, ""},
		{"a start refuses a file after --error-log", []string{"SITE", "--error-log", "e.log"}, false, unset, command{}, "--error-log takes its value as --error-log=FILE"},
		{"the engine reads --trusted-proxies", []string{"drupack", "--trusted-proxies", "10.0.0.1,10.0.0.0/8", "project"}, true, unset,
			command{word: "start", target: "project", options: options{Listen: defaultListen, TrustedProxies: "10.0.0.1,10.0.0.0/8"}}, ""},
		{"the engine reads --host", []string{"drupack", "--host", "www.example.org", "project"}, true, unset,
			command{word: "start", target: "project", options: options{Listen: defaultListen, Host: "www.example.org"}}, ""},
		{"the engine refuses a word after --access-log", []string{"drupack", "--access-log", "a.log"}, true, unset, command{}, "--access-log takes its value as --access-log=FILE"},
		{"the engine refuses a second folder", []string{"drupack", "a", "b"}, true, unset, command{}, "Unknown argument b. Run ./fixture-site --help."},
		{"the engine refuses an option", []string{"drupack", "start", "--help"}, true, unset, command{}, "Unknown argument --help. Run ./fixture-site --help."},
		{"the engine answers --help first", []string{"drupack", "--help"}, true, unset, command{word: "help"}, ""},
		{"the engine answers -h first", []string{"drupack", "-h"}, true, unset, command{word: "help"}, ""},
		{"the engine's export", []string{"drupack", "export", "--uri", "u"}, true, unset, command{word: "export", words: []string{"--uri", "u"}}, ""},
		{"the engine's dr", []string{"drupack", "dr", "x"}, true, unset, command{word: "dr", words: []string{"x"}}, ""},
		{"trust keeps its words", []string{"SITE", "trust", "--uninstall"}, false, unset, command{word: "trust", words: []string{"--uninstall"}}, ""},
		{"the engine's trust is no folder", []string{"drupack", "trust"}, true, unset, command{word: "trust", words: []string{}}, ""},
		{"self-update keeps its words", []string{"drupack", "self-update", "--check"}, true, unset, command{word: "self-update", words: []string{"--check"}}, ""},
		{"clean keeps its words", []string{"SITE", "clean", "--dry-run"}, false, unset, command{word: "clean", words: []string{"--dry-run"}}, ""},
		{"the engine's php goes to the runtime", []string{"drupack", "php", "-r", "1;"}, true, unset, command{}, ""},
		{"the engine reads php-cli as a folder", []string{"drupack", "php-cli"}, true, unset,
			command{word: "start", target: "php-cli", options: options{Listen: defaultListen}}, ""},
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
		{"the engine refuses two folders", []string{"drupack", "stop", "a", "b"}, true, unset, command{}, "stop takes a folder alone. Run ./fixture-site --help."},
		{"the engine refuses an option", []string{"drupack", "stop", "--help"}, true, unset, command{}, "stop takes a folder alone. Run ./fixture-site --help."},
		{"drush goes to the launcher", []string{"SITE", "drush", "status"}, false, unset,
			command{word: "drush", options: options{DataDir: "./data"}, words: []string{"status"}}, ""},
		{"drush takes no words", []string{"SITE", "drush"}, false, unset,
			command{word: "drush", options: options{DataDir: "./data"}, words: []string{}}, ""},
		{"drush reads its options first", []string{"SITE", "drush", "--data-dir=d", "--listen", "[::1]:9", "user:login", "--no-browser"}, false, unset,
			command{word: "drush", options: options{DataDir: "d", Listen: "[::1]:9"}, words: []string{"user:login", "--no-browser"}}, ""},
		{"drush options after the first word stay Drush's", []string{"SITE", "drush", "status", "--data-dir", "x"}, false, unset,
			command{word: "drush", options: options{DataDir: "./data"}, words: []string{"status", "--data-dir", "x"}}, ""},
		{"drush hands --help to Drush", []string{"SITE", "drush", "--data-dir", "d", "--help"}, false, unset,
			command{word: "drush", options: options{DataDir: "d"}, words: []string{"--help"}}, ""},
		{"drush reads DRUPACK_DATA_DIR", []string{"SITE", "drush", "status"}, false, map[string]string{"DRUPACK_DATA_DIR": "env"},
			command{word: "drush", options: options{DataDir: "env"}, words: []string{"status"}}, ""},
		{"drush refuses an option with no value", []string{"SITE", "drush", "--host"}, false, unset, command{}, "Missing value for --host"},
		{"drush reads an option as no value", []string{"SITE", "drush", "--files-dir", "--listen", "x"}, false, unset, command{}, "Missing value for --files-dir"},
		{"the engine passes every word to Drush", []string{"drupack", "drush", "--data-dir", "x", "status"}, true, unset,
			command{word: "drush", words: []string{"--data-dir", "x", "status"}}, ""},
		{"the engine takes no Site data", []string{"drupack", "stop", "--data-dir", "x"}, true, unset, command{}, "stop takes a folder alone. Run ./fixture-site --help."},
		{"status takes ./data by default", []string{"SITE", "status"}, false, unset, command{word: "status", target: "./data"}, ""},
		{"status reads --data-dir", []string{"SITE", "status", "--data-dir=site"}, false, unset, command{word: "status", target: "site"}, ""},
		{"status refuses a start option", []string{"SITE", "status", "--listen", "127.0.0.1:1"}, false, unset, command{}, "Unknown argument: --listen"},
		{"status --help prints the help", []string{"SITE", "status", "--help"}, false, unset, command{word: "help"}, ""},
		{"the engine's status reads the working directory by default", []string{"drupack", "status"}, true, unset, command{word: "status", target: "."}, ""},
		{"the engine's status reads the folder named", []string{"drupack", "status", "project"}, true, unset, command{word: "status", target: "project"}, ""},
		{"the engine's status refuses two folders", []string{"drupack", "status", "a", "b"}, true, unset, command{}, "status takes a folder alone. Run ./fixture-site --help."},
		{"a folder named status takes ./status", []string{"drupack", "./status"}, true, unset, command{word: "start", target: "./status", options: options{Listen: defaultListen}}, ""},
		{"logs is reserved", []string{"drupack", "logs"}, true, unset, command{}, "logs is not a command. A folder named logs takes ./logs."},
		{"restart takes ./data by default", []string{"SITE", "restart"}, false, unset, command{word: "restart", target: "./data"}, ""},
		{"restart reads --data-dir", []string{"SITE", "restart", "--data-dir", "site"}, false, unset, command{word: "restart", target: "site"}, ""},
		{"restart refuses a start option", []string{"SITE", "restart", "--no-browser"}, false, unset, command{}, "Unknown argument: --no-browser"},
		{"the engine's restart reads the working directory by default", []string{"drupack", "restart"}, true, unset, command{word: "restart", target: "."}, ""},
		{"the engine's restart reads the folder named", []string{"drupack", "restart", "project"}, true, unset, command{word: "restart", target: "project"}, ""},
		{"the engine's restart refuses an option", []string{"drupack", "restart", "--listen"}, true, unset, command{}, "restart takes a folder alone. Run ./fixture-site --help."},
		{"a folder named restart takes ./restart", []string{"drupack", "./restart"}, true, unset, command{word: "start", target: "./restart", options: options{Listen: defaultListen}}, ""},
		{"a folder named logs takes ./logs", []string{"drupack", "./logs"}, true, unset, command{word: "start", target: "./logs", options: options{Listen: defaultListen}}, ""},
		{"a site has no logs word", []string{"SITE", "logs"}, false, unset, command{}, "Unknown command: logs. Run ./fixture-site --help."},
	} {
		var parent []string
		for name, value := range row.env {
			parent = append(parent, name+"="+value)
		}
		got, err := parse(Launch{Args: row.args, Parent: parent, Engine: row.engine, Name: "fixture-site", Invoked: "./fixture-site"})
		message := ""
		if err != nil {
			message = err.Error()
		}
		if !reflect.DeepEqual(got, row.want) || message != row.err {
			t.Errorf("%s: parse(%q) = %+v, %q, want %+v, %q", row.name, row.args, got, message, row.want, row.err)
		}
	}
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

func TestTheHelpLinksTheReferenceOfItsRelease(t *testing.T) {
	for release, want := range map[string]string{
		"1.0.0-beta23": "https://git.drupalcode.org/project/drupack/-/blob/1.0.0-beta23/docs/cli.md",
		"dev":          "https://git.drupalcode.org/project/drupack/-/blob/main/docs/cli.md",
	} {
		for _, engine := range []bool{false, true} {
			if got := helpText(Launch{Name: "fixture-site", Engine: engine}, release); !strings.HasSuffix(got, "\n"+want) {
				t.Errorf("release %s, engine %t: the help ends %q, want %q", release, engine, got[strings.LastIndex(got, "\n")+1:], want)
			}
		}
	}
}

// The help texts, the parser's tables and docs/cli.md name the same options and
// commands.
func TestHelpMatchesTheParserAndTheReference(t *testing.T) {
	reference := read(t, filepath.Join("..", "docs", "cli.md"))
	// applies holds each documented option's Applies cell, cut before " and ".
	applies := map[string]string{}
	for _, row := range regexp.MustCompile("(?m)^\\| `--([a-z-]+)` \\|.*\\| ([^|]+) \\|$").FindAllStringSubmatch(reference, -1) {
		applies[row[1]], _, _ = strings.Cut(row[2], " and ")
	}
	if got, want := slices.Sorted(maps.Keys(applies)), parserOptions(siteOptions); !slices.Equal(got, want) {
		t.Errorf("docs/cli.md names %v, the parser reads %v", got, want)
	}
	groupApplies := map[string]string{
		"Where the site lives, for start and drush:": "every start, `drush`",
		"Start options:":                     "every start",
		"Database options, for every start:": "every start",
		"First start options:":               "first start",
	}
	for _, g := range siteGroups {
		for _, o := range g.options {
			if applies[o.name] != groupApplies[g.title] {
				t.Errorf("--%s sits under %q, and docs/cli.md applies it to %q", o.name, g.title, applies[o.name])
			}
			database := o.name == "database" || strings.HasPrefix(o.name, "db-")
			if inGroup := g.title == "Database options, for every start:"; database != inGroup {
				t.Errorf("--%s sits under %q", o.name, g.title)
			}
		}
	}
	// The engine serves where a site lives: its first group holds the engine
	// options of the site's first group.
	for _, g := range engineGroups {
		for _, o := range g.options {
			lives := slices.ContainsFunc(siteGroups[0].options, func(s option) bool { return s.name == o.name })
			if served := g.title == engineGroups[0].title; lives != served {
				t.Errorf("the engine's --%s sits under %q", o.name, g.title)
			}
		}
	}
	want := []string{"start", "stop", "status", "restart", "drush", "php", "node", "npm", "npx", "export", "trust", "clean"}
	if got := helpCommands(helpText(Launch{Name: "fixture-site"}, "dev")); !slices.Equal(got, want) {
		t.Errorf("the site help lists %v, want %v", got, want)
	}
	for _, word := range []string{"stop", "status", "restart"} {
		usage := "SITE " + word
		for _, o := range stopOptions {
			usage += " [" + o.label() + "]"
		}
		for _, row := range []struct{ file, text, usage string }{
			{"docs/cli.md", reference, usage + "\n"},
			{"docs/cli.md", reference, "drupack " + word + " [DIR]\n"},
		} {
			if !strings.Contains(row.text, row.usage) {
				t.Errorf("%s does not hold %q", row.file, row.usage)
			}
		}
	}
}

// helpCommands lists the command words a help text describes, in order.
func helpCommands(help string) []string {
	var words []string
	for _, match := range regexp.MustCompile(`(?m)^  ([a-z][a-z, -]*?) {2,}`).FindAllStringSubmatch(help, -1) {
		words = append(words, strings.Split(match[1], ", ")...)
	}
	return words
}

func TestTheEngineHelpListsYesUnderStartOptions(t *testing.T) {
	_, group, _ := strings.Cut(helpText(Launch{Name: "drupack", Engine: true}, "dev"), "Start options:")
	group, _, _ = strings.Cut(group, "\n\n")
	if !strings.Contains(group, "\n  --yes ") {
		t.Errorf("the engine's start options read %q, want --yes among them", group)
	}
}

// The help names the executable in its first sentence, and starts each command
// line with the word that runs it.
func TestTheHelpStartsItsCommandsWithTheInvokedWord(t *testing.T) {
	for _, engine := range []bool{false, true} {
		help := helpText(Launch{Name: "fixture-site", Invoked: "./fixture-site", Engine: engine}, "dev")
		if !strings.HasPrefix(help, "fixture-site serves ") || !strings.Contains(help, "\nUsage: ./fixture-site [COMMAND]") || !strings.Contains(help, "\n  ./fixture-site drush ") {
			t.Errorf("engine %t: help = %q", engine, help)
		}
	}
}

func TestTheHelpFitsEightyColumns(t *testing.T) {
	// The longest name payload.MintedSegmentPattern admits.
	longest := "a" + strings.Repeat("0", 31)
	for _, l := range []Launch{{Name: longest, Invoked: longest}, {Name: "drupack", Invoked: "./drupack", Engine: true}} {
		for _, line := range strings.Split(helpText(l, "1.0.0-beta23"), "\n") {
			if len(line) > 80 {
				t.Errorf("the %s help line is %d columns: %q", l.Name, len(line), line)
			}
		}
	}
}

func TestEngineHelpMatchesTheParserAndTheReference(t *testing.T) {
	reference := read(t, filepath.Join("..", "docs", "cli.md"))
	section := reference[strings.Index(reference, "## The engine executable"):]
	names := helpCommands(helpText(Launch{Name: "drupack", Engine: true}, "dev"))
	for _, name := range names {
		if !strings.Contains(section, "drupack "+name) {
			t.Errorf("docs/cli.md's engine section omits %s", name)
		}
	}
	if want := []string{"start", "stop", "status", "restart", "drush", "dr", "composer", "php", "export", "trust", "clean", "self-update"}; !slices.Equal(names, want) {
		t.Errorf("the engine help lists %v, want %v", names, want)
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

func TestWithoutOptionsDropsTheSecrets(t *testing.T) {
	for _, row := range []struct {
		name        string
		words, want []string
	}{
		{"a line with no secret stays whole", []string{"start", "--listen", "127.0.0.1:9000", "--access-log=a.log"}, []string{"start", "--listen", "127.0.0.1:9000", "--access-log=a.log"}},
		{"a secret and its next word go", []string{"--db-user", "u", "--db-password", "p", "--no-browser"}, []string{"--db-user", "u", "--no-browser"}},
		{"a secret with = goes whole", []string{"--admin-password=a=b", "--site-name", "S"}, []string{"--site-name", "S"}},
		{"every repeat goes", []string{"--db-password", "p", "--db-password=q", "--admin-password", "r"}, nil},
	} {
		if got := withoutOptions(row.words, siteOptions, func(o option) bool { return o.secret }); !slices.Equal(got, row.want) {
			t.Errorf("%s: withoutOptions(%q) = %q, want %q", row.name, row.words, got, row.want)
		}
	}
}

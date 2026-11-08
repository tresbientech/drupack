package main

import (
	"os"
	"path/filepath"
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
		{"a bare line goes to PHP", []string{"SITE"}, false, unset, command{}, ""},
		{"a start goes to PHP", []string{"SITE", "start", "--data-dir", "x"}, false, unset, command{}, ""},
		{"stop after an option is a start's word", []string{"SITE", "--data-dir", "x", "stop"}, false, unset, command{}, ""},
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
		{"the engine takes no Site data", []string{"drupack", "stop", "--data-dir", "x"}, true, unset, command{}, "stop takes a folder alone. Run fixture-site --help."},
	} {
		lookup := func(name string) (string, bool) { value, set := row.env[name]; return value, set }
		got, err := parse(row.args, row.engine, lookup)
		message := ""
		if err != nil {
			message = err.Error()
		}
		if got != row.want || message != row.err {
			t.Errorf("%s: parse(%q) = %+v, %q, want %+v, %q", row.name, row.args, got, message, row.want, row.err)
		}
	}
}

// stop's usage line, rendered from the options the parser reads, stands in the
// help text and in docs/cli.md.
func TestStopUsageMatchesTheHelpAndTheReference(t *testing.T) {
	site := "stop"
	for _, o := range stopOptions {
		site += " [--" + o.name + " " + o.value + "]"
	}
	reference := read(t, filepath.Join("..", "docs", "cli.md"))
	for _, row := range []struct{ file, text, usage string }{
		{"application/launch.php", read(t, filepath.Join("..", "application", "launch.php")), "%1$s " + site + "\n"},
		{"docs/cli.md", reference, "SITE " + site + "\n"},
		{"engine/serve.php", read(t, filepath.Join("..", "engine", "serve.php")), "%1$s stop [DIR]\n"},
		{"docs/cli.md", reference, "drupack stop [DIR]\n"},
	} {
		if !strings.Contains(row.text, row.usage) {
			t.Errorf("%s does not hold %q", row.file, row.usage)
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

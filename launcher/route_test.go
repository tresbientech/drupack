package main

import (
	"path/filepath"
	"slices"
	"testing"
)

func TestRuntimeArguments(t *testing.T) {
	application := filepath.Join("cache", "app")
	serve := filepath.Join(application, "serve.php")
	for _, c := range []struct {
		name   string
		args   []string
		engine bool
		node   bool
		want   []string
	}{
		{"the Engine passes php on", []string{"drupack", "php", "-r", "echo 1;"}, true, false,
			[]string{"drupack", "php", "-r", "echo 1;"}},
		{"the Engine passes --version on", []string{"drupack", "--version"}, true, false,
			[]string{"drupack", "--version"}},
		{"the Engine passes -v on", []string{"drupack", "-v"}, true, false,
			[]string{"drupack", "-v"}},
		{"the Engine sends a version flag with company to serve.php", []string{"drupack", "--version", "x"}, true, false,
			[]string{"drupack", "php-cli", serve, "--version", "x"}},
		{"the Engine sends a bare start to serve.php", []string{"drupack"}, true, false,
			[]string{"drupack", "php-cli", serve}},
		{"the Engine sends a word to serve.php", []string{"drupack", "stop", "--data-dir", "d"}, true, false,
			[]string{"drupack", "php-cli", serve, "stop", "--data-dir", "d"}},
	} {
		t.Run(c.name, func(t *testing.T) {
			if got := runtimeArguments(c.args, application, c.engine, c.node); !slices.Equal(got, c.want) {
				t.Fatalf("runtimeArguments(%q) = %q, want %q", c.args, got, c.want)
			}
		})
	}
}

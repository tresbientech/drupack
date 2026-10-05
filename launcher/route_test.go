package main

import (
	"path/filepath"
	"slices"
	"testing"
)

func TestRuntimeArguments(t *testing.T) {
	application := filepath.Join("cache", "app")
	serve := filepath.Join(application, "serve.php")
	launch := filepath.Join(application, "launch.php")
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
		{"the Engine sends php-cli to serve.php", []string{"drupack", "php-cli", "x.php"}, true, false,
			[]string{"drupack", "php-cli", serve, "php-cli", "x.php"}},
		{"the Engine sends a bare start to serve.php", []string{"drupack"}, true, false,
			[]string{"drupack", "php-cli", serve}},
		{"the Engine sends a word to serve.php", []string{"drupack", "stop", "--data-dir", "d"}, true, false,
			[]string{"drupack", "php-cli", serve, "stop", "--data-dir", "d"}},
		{"a site sends a bare start to launch.php", []string{"demo"}, false, false,
			[]string{"demo", "php-cli", launch}},
		{"a site sends options to launch.php", []string{"demo", "--data-dir", "d"}, false, false,
			[]string{"demo", "php-cli", launch, "--data-dir", "d"}},
		{"a site keeps start for launch.php", []string{"demo", "start", "--foreground"}, false, false,
			[]string{"demo", "php-cli", launch, "start", "--foreground"}},
		{"a site keeps drush for launch.php", []string{"demo", "drush", "status"}, false, false,
			[]string{"demo", "php-cli", launch, "drush", "status"}},
		{"a site keeps stop for launch.php", []string{"demo", "stop"}, false, false,
			[]string{"demo", "php-cli", launch, "stop"}},
		{"a site sends --help to launch.php", []string{"demo", "--help"}, false, false,
			[]string{"demo", "php-cli", launch, "--help"}},
		{"a site sends an unknown word to launch.php", []string{"demo", "frobnicate"}, false, false,
			[]string{"demo", "php-cli", launch, "frobnicate"}},
		{"a site sends php to launch.php", []string{"demo", "php", "x.php"}, false, false,
			[]string{"demo", "php-cli", launch, "php", "x.php"}},
		{"a site passes php-cli on", []string{"demo", "php-cli", "x.php", "a"}, false, false,
			[]string{"demo", "php-cli", "x.php", "a"}},
		{"a site passes --version on", []string{"demo", "--version"}, false, false,
			[]string{"demo", "--version"}},
		{"a site carrying Node passes node on", []string{"demo", "node", "--version"}, false, true,
			[]string{"demo", "node", "--version"}},
		{"a site carrying Node passes npx on", []string{"demo", "npx", "cowsay"}, false, true,
			[]string{"demo", "npx", "cowsay"}},
		{"a site without Node sends npm to launch.php", []string{"demo", "npm", "ci"}, false, false,
			[]string{"demo", "php-cli", launch, "npm", "ci"}},
	} {
		t.Run(c.name, func(t *testing.T) {
			if got := runtimeArguments(c.args, application, c.engine, c.node); !slices.Equal(got, c.want) {
				t.Fatalf("runtimeArguments(%q) = %q, want %q", c.args, got, c.want)
			}
		})
	}
}

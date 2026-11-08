package main

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

func TestPlanArguments(t *testing.T) {
	application := filepath.Join("cache", "app")
	serve := filepath.Join(application, "serve.php")
	launch := filepath.Join(application, "launch.php")
	for _, c := range []struct {
		name   string
		args   []string
		engine bool
		want   []string
	}{
		{"the Engine passes php on", []string{"drupack", "php", "-r", "echo 1;"}, true,
			[]string{"drupack", "php", "-r", "echo 1;"}},
		{"the Engine passes --version on", []string{"drupack", "--version"}, true,
			[]string{"drupack", "--version"}},
		{"the Engine passes -v on", []string{"drupack", "-v"}, true,
			[]string{"drupack", "-v"}},
		{"the Engine sends a version flag with company to serve.php", []string{"drupack", "--version", "x"}, true,
			[]string{"drupack", "php-cli", serve, "--version", "x"}},
		{"the Engine sends php-cli to serve.php", []string{"drupack", "php-cli", "x.php"}, true,
			[]string{"drupack", "php-cli", serve, "php-cli", "x.php"}},
		{"the Engine sends a bare start to serve.php", []string{"drupack"}, true,
			[]string{"drupack", "php-cli", serve}},
		{"the Engine sends a word to serve.php", []string{"drupack", "stop", "--data-dir", "d"}, true,
			[]string{"drupack", "php-cli", serve, "stop", "--data-dir", "d"}},
		{"a site sends a bare start to launch.php", []string{"demo"}, false,
			[]string{"demo", "php-cli", launch}},
		{"a site sends options to launch.php", []string{"demo", "--data-dir", "d"}, false,
			[]string{"demo", "php-cli", launch, "--data-dir", "d"}},
		{"a site keeps start for launch.php", []string{"demo", "start", "--foreground"}, false,
			[]string{"demo", "php-cli", launch, "start", "--foreground"}},
		{"a site keeps stop for launch.php", []string{"demo", "stop"}, false,
			[]string{"demo", "php-cli", launch, "stop"}},
		{"a site sends --help to launch.php", []string{"demo", "--help"}, false,
			[]string{"demo", "php-cli", launch, "--help"}},
		{"a site sends an unknown word to launch.php", []string{"demo", "frobnicate"}, false,
			[]string{"demo", "php-cli", launch, "frobnicate"}},
		{"a site passes php on", []string{"demo", "php", "x.php"}, false,
			[]string{"demo", "php", "x.php"}},
		{"a site passes php-cli on", []string{"demo", "php-cli", "x.php", "a"}, false,
			[]string{"demo", "php-cli", "x.php", "a"}},
		{"a site passes --version on", []string{"demo", "--version"}, false,
			[]string{"demo", "--version"}},
		{"a site without Node sends npm to launch.php", []string{"demo", "npm", "ci"}, false,
			[]string{"demo", "php-cli", launch, "npm", "ci"}},
	} {
		t.Run(c.name, func(t *testing.T) {
			l := Launch{Args: c.args, Entry: "/runtime/dir/frankenphp", Application: application, Engine: c.engine}
			got := Plan(l)
			if got.Path != l.Entry {
				t.Errorf("Plan(%q).Path = %q, want the runtime", c.args, got.Path)
			}
			if !slices.Equal(got.Args, c.want) {
				t.Fatalf("Plan(%q).Args = %q, want %q", c.args, got.Args, c.want)
			}
		})
	}
}

func TestPlanRunsNode(t *testing.T) {
	bin := filepath.Join("node", "bin")
	for _, word := range []string{"node", "npm", "npx"} {
		t.Run(word, func(t *testing.T) {
			got := Plan(Launch{Args: []string{"demo", word, "--version", "an argument"}, Entry: "/runtime/dir/frankenphp", NodeBin: bin})
			path := filepath.Join(bin, nodeFile(word))
			if got.Path != path {
				t.Errorf("Path = %q, want %q", got.Path, path)
			}
			if want := []string{path, "--version", "an argument"}; !slices.Equal(got.Args, want) {
				t.Errorf("Args = %q, want %q", got.Args, want)
			}
			if !slices.ContainsFunc(got.Env, func(entry string) bool { return strings.HasPrefix(entry, "PATH="+bin) }) {
				t.Errorf("Env = %q, want PATH to start with %s", got.Env, bin)
			}
		})
	}
}

func TestPlanEnvironment(t *testing.T) {
	path := string(os.PathListSeparator)
	for _, c := range []struct {
		name  string
		edit  func(*Launch)
		want  map[string]string
		lacks []string
	}{
		{
			// FrankenPHP resolves php.ini through PHPRC, so a stale value would load the wrong settings.
			name: "PHPRC always names the runtime directory",
			edit: func(l *Launch) { l.Parent = []string{"PHPRC=/caller/chosen/path", "HOME=/home/user"} },
			want: map[string]string{"PHPRC": filepath.FromSlash("/runtime/dir"), "HOME": "/home/user"},
		},
		{
			// A caller behind a TLS-inspection proxy names their own bundle.
			name: "DRUPACK_CA_FILE keeps a caller's value",
			edit: func(l *Launch) { l.Parent = []string{"DRUPACK_CA_FILE=/proxy/bundle.pem"} },
			want: map[string]string{"DRUPACK_CA_FILE": "/proxy/bundle.pem"},
		},
		{
			name: "both variables point at the runtime when the caller sets neither",
			want: map[string]string{"PHPRC": filepath.FromSlash("/runtime/dir"), "DRUPACK_CA_FILE": "/runtime/dir/cacert.pem"},
		},
		{
			// A glibc runtime runs under the host's loader, which would load the caller's code.
			name: "the loader controls are dropped",
			edit: func(l *Launch) {
				l.Parent = []string{"LD_PRELOAD=/tmp/evil.so", "LD_LIBRARY_PATH=/tmp/lib", "LD_AUDIT=/tmp/audit.so",
					"GLIBC_TUNABLES=glibc.malloc.check=0", "LD_PRELOADED_BY_NOBODY=keep"}
			},
			want:  map[string]string{"LD_PRELOADED_BY_NOBODY": "keep"},
			lacks: []string{"LD_PRELOAD", "LD_LIBRARY_PATH", "LD_AUDIT", "GLIBC_TUNABLES"},
		},
		{
			name: "the TLS folder is named",
			edit: func(l *Launch) { l.TLSDir = "/home/user/.config/drupack/tls" },
			want: map[string]string{"DRUPACK_TLS_DIR": "/home/user/.config/drupack/tls"},
		},
		{
			name: "the TLS folder replaces an inherited one",
			edit: func(l *Launch) {
				l.TLSDir = "/caller/tls"
				l.Parent = []string{"DRUPACK_TLS_DIR=caller/tls"}
			},
			want: map[string]string{"DRUPACK_TLS_DIR": "/caller/tls"},
		},
		{
			name:  "an account with no config folder gets no TLS folder",
			lacks: []string{"DRUPACK_TLS_DIR"},
		},
		{
			name: "a caller who empties DRUPACK_TLS_DIR keeps it empty",
			edit: func(l *Launch) { l.Parent = []string{"DRUPACK_TLS_DIR="} },
			want: map[string]string{"DRUPACK_TLS_DIR": ""},
		},
		{
			name: "a site names itself and its release",
			want: map[string]string{
				"DRUPACK_RUNTIME_NAME":         "demo",
				"DRUPACK_RUNTIME_SITE_VERSION": "1.2.3",
				"DRUPACK_RUNTIME_COMPONENTS":   "Drupal 11.2.0",
				"DRUPACK_RUNTIME_APP_DIR":      "/cache/app",
			},
		},
		{
			name: "a site replaces an inherited application and drops an inherited cache root",
			edit: func(l *Launch) {
				l.Parent = []string{"DRUPACK_RUNTIME_APP_DIR=/other/app", "DRUPACK_RUNTIME_CACHE_ROOT=/other/cache"}
			},
			want:  map[string]string{"DRUPACK_RUNTIME_APP_DIR": "/cache/app"},
			lacks: []string{"DRUPACK_RUNTIME_CACHE_ROOT"},
		},
		{
			name: "the engine drops an inherited application and names its cache root",
			edit: func(l *Launch) {
				l.Engine = true
				l.Parent = []string{"DRUPACK_RUNTIME_APP_DIR=/other/app"}
			},
			want:  map[string]string{"DRUPACK_RUNTIME_CACHE_ROOT": "/cache"},
			lacks: []string{"DRUPACK_RUNTIME_APP_DIR"},
		},
		{
			name: "a file without Node leaves PATH alone",
			edit: func(l *Launch) { l.Parent = []string{"PATH=/bin"} },
			want: map[string]string{"PATH": "/bin"},
		},
		{
			name: "a file carrying Node puts it first on PATH",
			edit: func(l *Launch) {
				l.NodeBin = "/node/bin"
				l.Parent = []string{"PATH=/bin"}
			},
			want: map[string]string{"PATH": "/node/bin" + path + "/bin"},
		},
		{
			name: "Windows finds PATH under any case",
			edit: func(l *Launch) {
				l.FoldCase = true
				l.NodeBin = "/node/bin"
				l.Parent = []string{"Path=/bin", "phprc=/stale", "Drupack_CA_File=/proxy/bundle.pem"}
			},
			want:  map[string]string{"PATH": "/node/bin" + path + "/bin", "PHPRC": filepath.FromSlash("/runtime/dir"), "Drupack_CA_File": "/proxy/bundle.pem"},
			lacks: []string{"Path", "phprc", "DRUPACK_CA_FILE"},
		},
		{
			name: "Unix keeps variables whose names differ only in case",
			edit: func(l *Launch) { l.Parent = []string{"phprc=/kept"} },
			want: map[string]string{"phprc": "/kept", "PHPRC": filepath.FromSlash("/runtime/dir")},
		},
		{
			name: "a console a file manager opened is named",
			edit: func(l *Launch) { l.ConsoleOwned = true },
			want: map[string]string{"DRUPACK_RUNTIME_CONSOLE_OWNED": "1"},
		},
		{
			name:  "a console a shell opened is not",
			lacks: []string{"DRUPACK_RUNTIME_CONSOLE_OWNED"},
		},
	} {
		t.Run(c.name, func(t *testing.T) {
			l := Launch{
				Args:        []string{"./demo"},
				Entry:       "/runtime/dir/frankenphp",
				Application: "/cache/app",
				Root:        "/cache",
				Name:        "demo",
				Version:     "1.2.3",
				Components:  "Drupal 11.2.0",
			}
			if c.edit != nil {
				c.edit(&l)
			}
			env := Plan(l).Env
			values := map[string]string{}
			for _, entry := range env {
				name, value, _ := strings.Cut(entry, "=")
				// os/exec resolves a duplicate to its last entry, which would override a caller's value.
				if _, twice := values[name]; twice {
					t.Fatalf("%s appears twice in %q", name, env)
				}
				values[name] = value
			}
			for name, want := range c.want {
				if got, set := values[name]; !set || got != want {
					t.Errorf("%s = %q, %v, want %q", name, got, set, want)
				}
			}
			for _, name := range c.lacks {
				if got, set := values[name]; set {
					t.Errorf("%s = %q, want it unset", name, got)
				}
			}
		})
	}
}

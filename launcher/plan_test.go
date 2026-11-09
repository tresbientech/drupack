package main

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

func TestPlanPassesTheReadersLineOn(t *testing.T) {
	for _, c := range []struct {
		name   string
		args   []string
		engine bool
	}{
		{"the Engine passes php on", []string{"drupack", "php", "-r", "echo 1;"}, true},
		{"the Engine passes --version on", []string{"drupack", "--version"}, true},
		{"the Engine passes -v on", []string{"drupack", "-v"}, true},
		{"a site passes php on", []string{"demo", "php", "x.php"}, false},
		{"a site passes php-cli on", []string{"demo", "php-cli", "x.php", "a"}, false},
		{"a site passes --version on", []string{"demo", "--version"}, false},
	} {
		t.Run(c.name, func(t *testing.T) {
			l := Launch{Args: c.args, Entry: "/runtime/dir/frankenphp", Application: filepath.Join("cache", "app"), Engine: c.engine}
			got := Plan(l)
			if got.Path != l.Entry {
				t.Errorf("Plan(%q).Path = %q, want the runtime", c.args, got.Path)
			}
			if !slices.Equal(got.Args, c.args) {
				t.Fatalf("Plan(%q).Args = %q, want the line unchanged", c.args, got.Args)
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

func TestEnvReader(t *testing.T) {
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
			name: "a site names itself and its release",
			want: map[string]string{
				"DRUPACK_RUNTIME_NAME":         "demo",
				"DRUPACK_RUNTIME_SITE_VERSION": "1.2.3",
				"DRUPACK_RUNTIME_COMPONENTS":   "Drupal 11.2.0",
				"DRUPACK_RUNTIME_APP_DIR":      "/cache/app",
			},
		},
		{
			name: "a site replaces an inherited application",
			edit: func(l *Launch) { l.Parent = []string{"DRUPACK_RUNTIME_APP_DIR=/other/app"} },
			want: map[string]string{"DRUPACK_RUNTIME_APP_DIR": "/cache/app"},
		},
		{
			name: "the engine drops an inherited application",
			edit: func(l *Launch) {
				l.Engine = true
				l.Parent = []string{"DRUPACK_RUNTIME_APP_DIR=/other/app"}
			},
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

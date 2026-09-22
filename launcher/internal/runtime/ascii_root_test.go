package runtime

import (
	"bytes"
	"io"
	"strings"
	"testing"
)

// fakeResolve stands in for GetShortPathName: aliases maps a candidate to the
// short name Windows would report for it, so every rung in resolveASCIIRoot
// runs against a table on this host without calling Windows.
func fakeResolve(aliases map[string]string) func(string) (string, error) {
	return func(candidate string) (string, error) {
		if alias, ok := aliases[candidate]; ok {
			return alias, nil
		}
		return candidate, nil
	}
}

func noopMkdir(string) error { return nil }

func TestResolveASCIIRootRungOrder(t *testing.T) {
	cases := []struct {
		name     string
		root     string
		fallback string
		aliases  map[string]string
		want     string
	}{
		{
			// root is checked before fallback: reversing the rungs would resolve
			// fallback's own (unaliased, ASCII) form instead and return the wrong
			// candidate, so this case alone catches an order regression.
			name:     "an ASCII candidate is kept",
			root:     "/cache/root",
			fallback: "/tmp/fallback",
			aliases:  map[string]string{},
			want:     "/cache/root",
		},
		{
			// A long-but-ASCII segment (a test method name, an installed product
			// name) never reaches the resolver: Windows would alias it too, which
			// no reader asked for, so the aliases entry below must never be taken.
			name:     "an already-ASCII candidate is kept even when Windows would alias it",
			root:     "/cache/a-rather-long-but-entirely-ascii-directory-name/cache",
			fallback: "/tmp/fallback",
			aliases: map[string]string{
				"/cache/a-rather-long-but-entirely-ascii-directory-name/cache": "/cache/LONGDI~1/cache",
			},
			want: "/cache/a-rather-long-but-entirely-ascii-directory-name/cache",
		},
		{
			name:     "a non-ASCII candidate with an ASCII alias takes the alias",
			root:     "/cache/tëst",
			fallback: "/tmp/fallback",
			aliases:  map[string]string{"/cache/tëst": "/cache/TST~1"},
			want:     "/cache/TST~1",
		},
		{
			name:     "root has no alias, the fallback does",
			root:     "/cache/田中",
			fallback: "/tmp/田中/fallback",
			aliases: map[string]string{
				"/cache/田中":        "/cache/田中",
				"/tmp/田中/fallback": "/tmp/TANAKA~1/fallback",
			},
			want: "/tmp/TANAKA~1/fallback",
		},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got, err := resolveASCIIRoot(c.root, c.fallback, noopMkdir, fakeResolve(c.aliases), io.Discard)
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
			if got != c.want {
				t.Fatalf("got %q, want %q", got, c.want)
			}
		})
	}
}

// TestResolveASCIIRootRunsMkdirBeforeResolve pins the order a refactor could
// silently drop: mkdir must run, and must run before resolve, for the
// candidate under test. A short name exists only for a path that exists, so
// reordering these breaks on a real Windows volume while staying green here.
func TestResolveASCIIRootRunsMkdirBeforeResolve(t *testing.T) {
	var calls []string
	mkdir := func(candidate string) error {
		calls = append(calls, "mkdir:"+candidate)
		return nil
	}
	resolve := func(candidate string) (string, error) {
		calls = append(calls, "resolve:"+candidate)
		return "/cache/TST~1", nil
	}

	got, err := resolveASCIIRoot("/cache/tëst", "/tmp/fallback", mkdir, resolve, io.Discard)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if got != "/cache/TST~1" {
		t.Fatalf("got %q, want %q", got, "/cache/TST~1")
	}
	want := "mkdir:/cache/tëst,resolve:/cache/tëst"
	if joined := strings.Join(calls, ","); joined != want {
		t.Fatalf("got call order %q, want %q", joined, want)
	}
}

// TestResolveASCIIRootPrintsANoticeWhenItFallsThrough covers the swap Root's
// own doc comment promises never happens silently: falling from root to the
// fallback rung names both candidates and the reason in one line.
func TestResolveASCIIRootPrintsANoticeWhenItFallsThrough(t *testing.T) {
	root := "/cache/田中"
	fallback := "/tmp/田中/fallback"
	aliases := map[string]string{
		root:     root, // resolves, but the alias is still not ASCII
		fallback: "/tmp/TANAKA~1/fallback",
	}
	var notice bytes.Buffer

	got, err := resolveASCIIRoot(root, fallback, noopMkdir, fakeResolve(aliases), &notice)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if got != "/tmp/TANAKA~1/fallback" {
		t.Fatalf("got %q, want %q", got, "/tmp/TANAKA~1/fallback")
	}

	line := notice.String()
	if line == "" {
		t.Fatal("expected a notice when the fallback rung took over")
	}
	if !strings.Contains(line, root) {
		t.Fatalf("notice %q does not name the cache root that failed, %q", line, root)
	}
	if !strings.Contains(line, fallback) {
		t.Fatalf("notice %q does not name the root in use, %q", line, fallback)
	}
}

func TestResolveASCIIRootStopsLoudlyWithNoASCIIFormAnywhere(t *testing.T) {
	root := `C:\Users\田中\AppData\Local\Drupack\runtime`
	fallback := `C:\Users\田中\AppData\Local\Temp\Drupack\runtime`
	aliases := map[string]string{root: root, fallback: fallback}

	_, err := resolveASCIIRoot(root, fallback, noopMkdir, fakeResolve(aliases), io.Discard)
	if err == nil {
		t.Fatal("expected an error when neither rung resolves to ASCII")
	}
	if !strings.Contains(err.Error(), root) {
		t.Fatalf("error %q does not name the cache root %q", err.Error(), root)
	}
	if !strings.Contains(err.Error(), fallback) {
		t.Fatalf("error %q does not name the fallback %q", err.Error(), fallback)
	}
	if !strings.Contains(err.Error(), "DRUPACK_CACHE_DIR") {
		t.Fatalf("error %q does not name DRUPACK_CACHE_DIR", err.Error())
	}
}

func TestIsASCII(t *testing.T) {
	cases := []struct {
		s    string
		want bool
	}{
		{"C:\\Users\\alice\\AppData\\Local\\Drupack", true},
		{"C:\\Users\\tëst\\AppData\\Local\\Drupack", false},
		{"C:\\Users\\Ольга\\AppData\\Local\\Drupack", false},
		{"C:\\Users\\田中\\AppData\\Local\\Drupack", false},
		{"", true},
	}
	for _, c := range cases {
		if got := isASCII(c.s); got != c.want {
			t.Errorf("isASCII(%q) = %v, want %v", c.s, got, c.want)
		}
	}
}

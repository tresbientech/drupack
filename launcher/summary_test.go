package main

import "testing"

func TestShownPathWritesHomeAsTilde(t *testing.T) {
	for _, c := range []struct {
		home, path string
		fold       bool
		want       string
	}{
		{"/home/u", "/home/u", false, "~"},
		{"/home/u", "/home/u/.cache/drupack/", false, "~/.cache/drupack/"},
		{"/home/u/", "/home/u/site", false, "~/site"},
		{"/home/u", "/home/u2/site", false, "/home/u2/site"},
		{"/home/u", "/tmp/site", false, "/tmp/site"},
		{"/home/u", "/home", false, "/home"},
		{"/", "/srv/site", false, "/srv/site"},
		{"", "/srv/site", false, "/srv/site"},
		{"C:/Users/U", "c:/users/u/AppData/drupack", true, "~/AppData/drupack"},
		{"C:/Users/U", "c:/users/u/AppData", false, "c:/users/u/AppData"},
	} {
		if got := shownPath(c.home, c.path, c.fold); got != c.want {
			t.Errorf("shownPath(%q, %q, %v) = %q, want %q", c.home, c.path, c.fold, got, c.want)
		}
	}
}

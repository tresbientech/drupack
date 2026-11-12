package main

import "testing"

func TestBrowserPage(t *testing.T) {
	const link, install, url = "http://127.0.0.1:3787/user/reset/1", "http://127.0.0.1:3787/core/install.php", "http://127.0.0.1:3787"
	for _, row := range []struct {
		name      string
		noBrowser bool
		person    bool
		bind      string
		pages     []string
		want      string
	}{
		{"the login link comes first", false, true, "127.0.0.1", []string{link, install, url}, link},
		{"an uninstalled folder opens the installer", false, true, "127.0.0.1", []string{"", install, url}, install},
		{"no link and no installer opens the URL", false, true, "::1", []string{"", "", url}, url},
		{"--no-browser opens nothing", true, true, "127.0.0.1", []string{link, install, url}, ""},
		{"a bind outside loopback opens nothing", false, true, "0.0.0.0", []string{link, install, url}, ""},
		{"a start nobody watches opens nothing", false, false, "127.0.0.1", []string{link, install, url}, ""},
	} {
		c := command{options: options{NoBrowser: row.noBrowser}}
		if got := c.browserPage(row.person, row.bind, row.pages...); got != row.want {
			t.Errorf("%s: got %q, want %q", row.name, got, row.want)
		}
	}
}

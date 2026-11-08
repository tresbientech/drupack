package main

import (
	"crypto/sha256"
	"encoding/hex"
	"net"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

func TestSiteToken(t *testing.T) {
	sum := sha256.Sum256([]byte("/Users/Ann/Site Data"))
	if got := siteToken("/Users/Ann/Site Data", false); got != hex.EncodeToString(sum[:]) {
		t.Errorf("unfolded token = %s", got)
	}
	// Windows spells one path in several casings, so the hash reads one.
	if siteToken("/a/B", true) != siteToken("/a/b", true) || siteToken("/a/B", false) == siteToken("/a/b", false) {
		t.Error("folding decides whether casings share a token")
	}
}

func TestLoopback(t *testing.T) {
	for bind, want := range map[string]bool{"127.0.0.1": true, "127.0.0.2": true, "::1": true, "0.0.0.0": false, "::": false, "192.168.1.5": false} {
		if loopback(bind) != want {
			t.Errorf("loopback(%s) = %v", bind, !want)
		}
	}
}

func TestPortTakenProbesAWildcardOnItsOwnLoopback(t *testing.T) {
	for _, row := range []struct{ listen, wildcard string }{{"127.0.0.1:0", "0.0.0.0"}, {"[::1]:0", "::"}} {
		listener, err := net.Listen("tcp", row.listen)
		if err != nil {
			t.Skipf("%s: %v", row.listen, err)
		}
		port := listener.Addr().(*net.TCPAddr).Port
		if !portTaken(row.wildcard, port) {
			t.Errorf("%s:%d reads as free", row.wildcard, port)
		}
		listener.Close()
		if portTaken(row.wildcard, port) {
			t.Errorf("%s:%d reads as taken once closed", row.wildcard, port)
		}
	}
}

func TestSiteListener(t *testing.T) {
	static := siteconfig.Site{Static: &siteconfig.Static{}}
	for _, row := range []struct {
		name         string
		site         siteconfig.Site
		listen, flag string
		want         string
	}{
		{"a static site takes the next port", static, "127.0.0.1:8080", "", ""},
		{"a static port on the site's own", static, "127.0.0.1:8080", "127.0.0.1:8080", "--static-listen must name a port other than the site's 8080"},
		{"a site without a static listener", siteconfig.Site{}, "127.0.0.1:8080", "127.0.0.1:8081", "--static-listen needs a site whose composer.json sets extra.drupack.static"},
		{"a bad listener", siteconfig.Site{}, "localhost:8080", "", "--listen requires IP:PORT, with IPv6 enclosed in brackets"},
	} {
		bind, port, _, err := siteListener(row.site, row.listen, row.flag)
		switch {
		case row.want == "" && (err != nil || bind != "127.0.0.1" || port != 8080):
			t.Errorf("%s: %s %d %v", row.name, bind, port, err)
		case row.want != "" && (err == nil || err.Error() != row.want):
			t.Errorf("%s: %v, want %s", row.name, err, row.want)
		}
	}
}

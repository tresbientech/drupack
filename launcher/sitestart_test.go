package main

import (
	"crypto/sha256"
	"encoding/hex"
	"net"
	"os"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/payload"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

func TestCheckDatabase(t *testing.T) {
	for _, row := range []struct {
		name, option, inherited string
		refused                 bool
	}{
		{"no choice", "", "", false},
		{"sqlite", "sqlite", "", false},
		{"mysql", "mysql", "", false},
		{"pgsql", "pgsql", "", false},
		{"a typed driver nobody ships", "oracle", "", true},
		{"an inherited driver nobody ships", "", "oracle", true},
		{"a typed driver over an inherited one", "pgsql", "oracle", false},
	} {
		words := []string{"--foreground"}
		if row.option != "" {
			words = append(words, "--database", row.option)
		}
		f := Facts{}
		if row.inherited != "" {
			f.Parent = []string{"DRUPACK_DATABASE=" + row.inherited}
		}
		err := checkDatabase(parseLine(t, false, words...), f)
		if row.refused && (err == nil || err.Error() != "--database requires sqlite, mysql, or pgsql") || !row.refused && err != nil {
			t.Errorf("%s: checkDatabase = %v", row.name, err)
		}
	}
}

func TestServedRefusesWhenNobodyWatches(t *testing.T) {
	if payload.Terminal(os.Stdin) {
		t.Skip("a terminal on stdin makes the start hand over")
	}
	f := newSite(t, siteconfig.Site{Port: 7225})
	f.record(t, `{"listen":"127.0.0.1:9000","host":"localhost","scheme":"https"}`)
	s := packagedStart{Facts: Facts{Launch: f.launch(), Data: f.data}}
	plan := startPlan{Bind: "127.0.0.1", Port: 7225, Host: "localhost"}
	if err, want := s.held(plan), "Another fixture-site start is preparing this Site data: "+f.data; err == nil || err.Error() != want {
		t.Errorf("a holder still preparing: %v, want %q", err, want)
	}
	s.installed = true
	if err, want := s.held(plan), "fixture-site already serves this Site data at https://localhost:9000/: "+f.data; err == nil || err.Error() != want {
		t.Errorf("a served site: %v, want %q", err, want)
	}
}

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

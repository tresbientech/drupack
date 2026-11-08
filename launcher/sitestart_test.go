package main

import (
	"crypto/sha256"
	"encoding/hex"
	"net"
	"testing"
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

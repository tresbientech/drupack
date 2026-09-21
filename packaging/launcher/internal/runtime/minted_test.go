package runtime

import (
	"strings"
	"testing"
)

func TestMintedSegment(t *testing.T) {
	cases := []struct {
		name    string
		segment string
		want    bool
	}{
		{"a version key", "vdev-27e7e19d151a", true},
		{"an app entry", "r2e2893a48a83", true},
		{"single letter", "v", true},
		{"leading digit", "1dev-27e7e19d151a", false},
		{"leading dot", ".hidden", false},
		{"uppercase letter", "Vdev-27e7e19d151a", false},
		{"embedded space", "v1.0.0 beta-27e7e19d151a", false},
		{"empty", "", false},
		{"at the length limit", "v" + strings.Repeat("a", 31), true},
		{"past the length limit", "v" + strings.Repeat("a", 32), false},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := MintedSegment(c.segment); got != c.want {
				t.Fatalf("MintedSegment(%q) = %v, want %v", c.segment, got, c.want)
			}
		})
	}
}

//go:build unix

package runtime

import "testing"

func TestTrustedOwner(t *testing.T) {
	for _, row := range []struct {
		name     string
		uid, gid uint32
		want     bool
	}{
		{"mine", 1000, 50, true},
		{"root's", 0, 50, true},
		{"another account's, in my group", 1001, 82, true},
		{"another account's, outside my groups", 1002, 50, false},
	} {
		if got := trustedOwner(row.uid, row.gid, 1000, []int{82}); got != row.want {
			t.Errorf("%s: trustedOwner = %v, want %v", row.name, got, row.want)
		}
	}
}

//go:build unix

package runtime

import "testing"

// A server account and a deploy account share a checkout through a group: the
// server's drush reads vendor/ files the deploy account owns.
func TestTrustedOwner(t *testing.T) {
	const me, deploy, stranger = 1000, 1001, 1002
	const www, staff = 82, 50
	groups := []int{www}
	cases := []struct {
		name     string
		uid, gid uint32
		want     bool
	}{
		{"mine", me, staff, true},
		{"root's", 0, staff, true},
		{"another account's, in my group", deploy, www, true},
		{"another account's, outside my groups", stranger, staff, false},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := trustedOwner(c.uid, c.gid, me, groups); got != c.want {
				t.Errorf("trustedOwner(%d, %d) = %v, want %v", c.uid, c.gid, got, c.want)
			}
		})
	}
}

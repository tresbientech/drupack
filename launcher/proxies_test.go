package main

import (
	"slices"
	"testing"
)

func TestTrustedProxies(t *testing.T) {
	for _, row := range []struct {
		written string
		want    []string
		err     string
	}{
		{"", nil, ""},
		{"192.168.0.58", []string{"192.168.0.58/32"}, ""},
		{"192.168.0.58/32,10.0.0.0/8", []string{"192.168.0.58/32", "10.0.0.0/8"}, ""},
		{"10.1.2.3/8", []string{"10.0.0.0/8"}, ""},
		{"::1,fd00::/8", []string{"::1/128", "fd00::/8"}, ""},
		{"0.0.0.0/0", nil, "--trusted-proxies refuses 0.0.0.0/0, which trusts every address"},
		{"::/0", nil, "--trusted-proxies refuses ::/0, which trusts every address"},
		{"10.0.0.1,", nil, `--trusted-proxies takes addresses and CIDR ranges with commas between them, and "" is neither`},
		{"10.0.0.1 10.0.0.2", nil, `--trusted-proxies takes addresses and CIDR ranges with commas between them, and "10.0.0.1 10.0.0.2" is neither`},
		{"proxy.example", nil, `--trusted-proxies takes addresses and CIDR ranges with commas between them, and "proxy.example" is neither`},
		{"private_ranges", nil, `--trusted-proxies takes addresses and CIDR ranges with commas between them, and "private_ranges" is neither`},
		{"10.0.0.0/33", nil, `--trusted-proxies takes addresses and CIDR ranges with commas between them, and "10.0.0.0/33" is neither`},
	} {
		got, err := trustedProxies(row.written)
		switch {
		case row.err == "" && (err != nil || !slices.Equal(got, row.want)):
			t.Errorf("trustedProxies(%q) = %v, %v, want %v", row.written, got, err, row.want)
		case row.err != "" && (err == nil || err.Error() != row.err):
			t.Errorf("trustedProxies(%q) error %v, want %q", row.written, err, row.err)
		}
	}
}

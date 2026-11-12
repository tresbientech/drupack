package main

import (
	"fmt"
	"net/netip"
	"strings"
)

// trustedProxies reads the value of --trusted-proxies, "" for a start without
// it: addresses and ranges with commas between them. The server takes a
// client's address from a forwarded header only when one of them sent the
// request. A range of every address would let any client name its own, so it
// is refused.
func trustedProxies(written string) ([]string, error) {
	if written == "" {
		return nil, nil
	}
	var ranges []string
	for entry := range strings.SplitSeq(written, ",") {
		prefix, err := netip.ParsePrefix(entry)
		if address, plain := netip.ParseAddr(entry); plain == nil {
			prefix, err = netip.PrefixFrom(address, address.BitLen()), nil
		}
		if err != nil {
			return nil, fmt.Errorf("--%s takes addresses and CIDR ranges with commas between them, and %q is neither", trustedProxiesOption.name, entry)
		}
		if prefix.Bits() == 0 {
			return nil, fmt.Errorf("--%s refuses %s, which trusts every address", trustedProxiesOption.name, entry)
		}
		ranges = append(ranges, prefix.Masked().String())
	}
	return ranges, nil
}

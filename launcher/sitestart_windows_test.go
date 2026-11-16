package main

import (
	"errors"
	"os/exec"
	"regexp"
	"strconv"
	"testing"
)

// excludedRange is a row of netsh's port exclusion table: the first and last port.
// netsh marks an administered exclusion with *, and a bind there succeeds, so the
// pattern leaves those rows out.
var excludedRange = regexp.MustCompile(`(?m)^\s*(\d+)\s+(\d+)\s*$`)

// Hyper-V and WSL reserve TCP port ranges where a bind fails with WSAEACCES,
// which a connect alone reads as free. A service such as WSDAPI can also listen
// on a reserved port, where the connect answers first, so those ports are passed over.
func TestPortHeldRefusesAReservedPort(t *testing.T) {
	out, err := exec.Command("netsh", "interface", "ipv4", "show", "excludedportrange", "protocol=tcp").Output()
	if err != nil {
		t.Fatalf("netsh: %v", err)
	}
	for _, row := range excludedRange.FindAllSubmatch(out, -1) {
		port, err := strconv.Atoi(string(row[1]))
		if err != nil {
			t.Fatal(err)
		}
		err = portHeld("127.0.0.1", port)
		if errors.Is(err, errListening) {
			continue
		}
		if err == nil {
			t.Errorf("reserved port %d reads as free", port)
		}
		return
	}
	t.Skipf("this host reserves no TCP port without a listener:\n%s", out)
}

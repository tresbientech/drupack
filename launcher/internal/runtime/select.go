package runtime

import (
	"fmt"
	"os"
	"strings"
)

// LibcVariable names the environment variable that picks a runtime by libc.
const LibcVariable = "DRUPACK_LIBC"

// Choice is one runtime a launcher carries: the libc it was linked against,
// and the program interpreter that libc needs present on the host.
type Choice struct {
	Libc        string
	Interpreter string
}

// Select returns the index of the runtime to run. A launcher carrying one
// runtime runs it and reads nothing, so a variable set for a Linux host does
// not stop a macOS or Windows start. Otherwise libc decides when it names one
// of the carried runtimes, and an unknown value stops the start rather than
// serving a runtime the caller did not ask for. With no variable set, the
// first runtime whose interpreter the host has wins, which puts the dynamic
// build ahead of the static one and falls to static on a host without it.
func Select(choices []Choice, libc string) (int, error) {
	if len(choices) == 1 {
		return 0, nil
	}
	if libc != "" {
		for index, choice := range choices {
			if choice.Libc == libc {
				return index, nil
			}
		}
		return 0, fmt.Errorf("%s=%s names no runtime this build carries: %s",
			LibcVariable, libc, strings.Join(libcNames(choices), ", "))
	}
	for index, choice := range choices {
		if choice.Interpreter == "" {
			return index, nil
		}
		if _, err := os.Stat(choice.Interpreter); err == nil {
			return index, nil
		}
	}
	return 0, fmt.Errorf("no runtime this build carries runs on this host: %s",
		strings.Join(libcNames(choices), ", "))
}

// libcNames lists the carried runtimes for a message a reader acts on.
func libcNames(choices []Choice) []string {
	names := make([]string, 0, len(choices))
	for _, choice := range choices {
		names = append(names, choice.Libc)
	}
	return names
}

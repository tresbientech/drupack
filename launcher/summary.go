package main

import (
	"fmt"
	"os"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/fsguard"
)

// writeRows writes each row whose value is not "" as an indented line. Each label
// carries the padding that aligns the values of its summary.
func writeRows(summary *strings.Builder, rows [][2]string) {
	for _, row := range rows {
		if row[1] != "" {
			fmt.Fprintf(summary, "  %s %s\n", row[0], row[1])
		}
	}
}

// shown is path as a summary prints it, with the home directory written as ~.
func (l Launch) shown(path string) string {
	// A process started with no HOME, such as a systemd unit, has no home to shorten.
	home, _ := os.UserHomeDir()
	return shownPath(home, path, l.FoldCase)
}

// shownPath writes home as ~ when path is home or sits under it. A home at the
// filesystem root shortens nothing. fold compares whatever the case, as Windows does.
func shownPath(home, path string, fold bool) string {
	home = strings.TrimSuffix(fsguard.Canonical(home), "/")
	canonical := fsguard.Canonical(path)
	if home == "" || len(canonical) < len(home) {
		return path
	}
	head, rest := canonical[:len(home)], canonical[len(home):]
	same := head == home || fold && strings.EqualFold(head, home)
	if !same || rest != "" && rest[0] != '/' {
		return path
	}
	return "~" + rest
}

package main

import (
	"cmp"
	"os/exec"
	"runtime"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/localtls"
)

// openBrowser asks the desktop to open address and does not wait for the browser.
func openBrowser(address string) {
	var command *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		command = exec.Command("rundll32", "url.dll,FileProtocolHandler", address)
	case "darwin":
		command = exec.Command("open", address)
	default:
		command = exec.Command("xdg-open", address)
	}
	_ = command.Start()
}

func (c command) browserPage(person bool, bind string, pages ...string) string {
	if !person || c.options.NoBrowser || !localtls.Loopback(bind) {
		return ""
	}
	return cmp.Or(pages...)
}

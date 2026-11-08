package main

import (
	"os/exec"
	goruntime "runtime"
)

// openBrowser asks the desktop to open address and does not wait for the browser.
func openBrowser(address string) {
	var command *exec.Cmd
	switch goruntime.GOOS {
	case "windows":
		command = exec.Command("rundll32", "url.dll,FileProtocolHandler", address)
	case "darwin":
		command = exec.Command("open", address)
	default:
		command = exec.Command("xdg-open", address)
	}
	_ = command.Start()
}

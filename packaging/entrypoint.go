package main

import (
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"time"
)

// version names the release. Each build script sets it with -ldflags.
var version = "dev"

const usage = `Usage: drupack [OPTIONS]
       drupack dr [OPTIONS] DRUSH_COMMAND

Options:
  --data-dir PATH            Site data directory, ./data by default
  --listen IP:PORT           Listener address, 127.0.0.1:7225 by default
  --host HOST                Permitted request host, localhost by default
  --database sqlite|mysql|pgsql
                             Database backend for a first start, sqlite by default
  --db-host, --db-port, --db-name, --db-user, --db-password
                             Connection details for mysql and pgsql
  --admin-user, --admin-password
                             Administrator account for a first start. Without them
                             a first start creates admin and prints a one-time
                             login link.
  --site-name NAME           Site name for a first start, "Drupal Mercury Demo" by default
  --no-browser               Do not open a browser
  --version, --help

Examples:
  drupack
  drupack --data-dir ./site --listen 127.0.0.1:9000
  drupack --admin-user admin --admin-password 'choose-a-password'
  drupack dr --data-dir ./site status
  drupack dr --data-dir ./site user:login`

// openWhenReady waits for the site to answer at address, then reports readiness and opens
// the browser on target when asked. launch.php starts this command before it replaces itself
// with the server. The terminal then keeps one readiness line even when no browser opens. A
// first start's target is a one-time login link, which a request spends, so the poll asks for
// address instead.
func openWhenReady(address string, target string, browser bool) {
	deadline := time.Now().Add(2 * time.Minute)
	for time.Now().Before(deadline) {
		response, err := http.Get(address)
		if err == nil {
			response.Body.Close()
			fmt.Println("Drupal is ready.")
			if browser {
				openBrowser(target)
			}
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
}

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

func init() {
	executable, err := os.Executable()
	if err != nil {
		panic(err)
	}
	if err := os.Setenv("DRUPACK_RUNTIME_BINARY", executable); err != nil {
		panic(err)
	}
	if len(os.Args) > 1 && os.Args[1] == "dr" {
		if err := os.Setenv("DRUPACK_RUNTIME_DRUSH", "1"); err != nil {
			panic(err)
		}
		os.Args = append([]string{os.Args[0], "php-cli", "launch.php"}, os.Args[2:]...)
		return
	}
	if len(os.Args) == 5 && os.Args[1] == "open-when-ready" {
		openWhenReady(os.Args[2], os.Args[3], os.Args[4] == "1")
		os.Exit(0)
	}
	if len(os.Args) == 2 && (os.Args[1] == "--help" || os.Args[1] == "-h") {
		fmt.Println(usage)
		os.Exit(0)
	}
	if len(os.Args) == 2 && (os.Args[1] == "--version" || os.Args[1] == "-v") {
		fmt.Println("Drupack " + version)
		os.Exit(0)
	}
	if len(os.Args) == 1 || strings.HasPrefix(os.Args[1], "-") {
		os.Args = append([]string{os.Args[0], "php-cli", "launch.php"}, os.Args[1:]...)
	}
}

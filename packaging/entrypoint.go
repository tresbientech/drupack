package main

import (
	"fmt"
	"os"
	"strings"
)

func init() {
	if len(os.Args) > 1 && os.Args[1] == "dr" {
		if err := os.Setenv("PORTABLE_DRUPAL_DRUSH", "1"); err != nil {
			panic(err)
		}
		os.Args = append([]string{os.Args[0], "php-cli", "launch.php"}, os.Args[2:]...)
		return
	}
	if len(os.Args) == 2 && (os.Args[1] == "--help" || os.Args[1] == "-h") {
		fmt.Println("Usage: portable-drupal [--data-dir PATH] [--listen IP:PORT] [--host HOST] [--database sqlite|mysql|pgsql]")
		os.Exit(0)
	}
	if len(os.Args) == 1 || strings.HasPrefix(os.Args[1], "-") {
		os.Args = append([]string{os.Args[0], "php-cli", "launch.php"}, os.Args[1:]...)
	}
}

// Command siteconfig validates a site's drupack.yml and writes its site.json.
package main

import (
	"fmt"
	"os"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

func main() {
	if len(os.Args) != 3 {
		fmt.Fprintln(os.Stderr, "usage: siteconfig SITE_DIRECTORY OUTPUT_DIRECTORY")
		os.Exit(2)
	}
	site, err := siteconfig.Read(os.Args[1])
	if err == nil {
		err = siteconfig.Write(site, os.Args[2])
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

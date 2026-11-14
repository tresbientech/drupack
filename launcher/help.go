package main

import (
	"fmt"
	"strings"
)

// siteHelp is the text a site's --help prints. %[1]s is the executable's name,
// %[2]s the option groups and %[3]s the reference URL.
const siteHelp = `%[1]s serves a Drupal site from this one file.

Usage: %[1]s [COMMAND] [OPTIONS]
       %[1]s --help | --version

Serve:
  start           Start the site in the background, as a bare command does
  stop            Stop the site serving the Site data of --data-dir
  status          Print whether it serves, its address and its start line
  restart         Stop its background server and start it with the same line

Run:
  drush           Run a Drush command against the site
  php             Run SCRIPT or -r CODE on the bundled PHP, with -d KEY[=VALUE]
                  settings and no other PHP option
  node, npm, npx  Run the bundled Node release, for a site that carries one
  export          Export the site with Tome, for a site with a static export

This computer:
  trust           Install the local root that lets every Drupack site serve
                  HTTPS. It may ask for a password. --uninstall removes it.
  clean           Remove the unpacked runtimes, applications and Node releases
                  from the cache. --dry-run lists them and removes nothing.
%[2]s
Examples:
  %[1]s
  %[1]s --data-dir ./site --listen 127.0.0.1:9000
  %[1]s --admin-password 'choose-a-password'
  %[1]s drush --data-dir ./site user:login
  %[1]s clean --dry-run

%[3]s`

// engineHelp is the text the engine's --help prints. %[1]s is the executable's
// name, %[2]s the option groups and %[3]s the reference URL.
const engineHelp = `%[1]s serves the Drupal project in a folder, with its own settings.

Usage: %[1]s [COMMAND] [DIR] [OPTIONS]
       %[1]s --help | --version

Serve:
  start        Serve the project in DIR, the working directory by default, in
               the background, as a bare command does
  stop         Stop the server of the project in DIR
  status       Print whether a server serves the project in DIR, its address
               and the line it started with
  restart      Stop the background server of the project in DIR and start it
               again with the same line

Run:
  drush        Run the Drush of the project holding the working directory
  dr           Run Drupal core's command line of that project
  composer     Run the bundled Composer in that project
  php          Run SCRIPT or -r CODE on the bundled PHP, with -d KEY[=VALUE]
               settings and no other PHP option
  export       Export that project with Tome at the static URL its last start
               served, or at the --uri given

This computer:
  trust        Install the local root that lets every Drupack site serve
               HTTPS. It may ask for a password. --uninstall removes it.
  clean        Remove the unpacked engine files and stopped folders' state
               from the cache. --dry-run lists them and removes nothing.
  self-update  Install the newest release over this file, or --check for one
%[2]s
DIR goes before an --access-log or --error-log written bare.

Examples:
  %[1]s
  %[1]s ./site --listen 127.0.0.1:9000
  %[1]s stop ./site
  %[1]s drush status
  %[1]s self-update --check

%[3]s`

// referenceURL is docs/cli.md at release, or at main for a build the self-update
// version pattern refuses, such as dev.
func referenceURL(release string) string {
	if !version.MatchString(release) {
		release = "main"
	}
	return "https://git.drupalcode.org/project/drupack/-/blob/" + release + "/docs/cli.md"
}

// helpText is the help of l's executable. release is the Drupack release the
// runtime manifest names; l.Version is a site's own release.
func helpText(l Launch, release string) string {
	if l.Engine {
		return fmt.Sprintf(engineHelp, l.Name, renderOptions(engineGroups), referenceURL(release))
	}
	return fmt.Sprintf(siteHelp, l.Name, renderOptions(siteGroups), referenceURL(release))
}

// renderOptions writes each group's title, then one line per option, its help
// aligned past the widest label of groups.
func renderOptions(groups []optionGroup) string {
	width := 0
	for _, g := range groups {
		for _, o := range g.options {
			width = max(width, len(o.label()))
		}
	}
	var b strings.Builder
	for _, g := range groups {
		fmt.Fprintf(&b, "\n%s\n", g.title)
		for _, o := range g.options {
			help := strings.ReplaceAll(o.help, "\n", "\n"+strings.Repeat(" ", width+4))
			fmt.Fprintf(&b, "  %-*s  %s\n", width, o.label(), help)
		}
	}
	return b.String()
}

func unknownCommand(word, name string) error {
	return fmt.Errorf("Unknown command: %s. Run %s --help.", word, name)
}

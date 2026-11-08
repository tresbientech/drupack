package main

import (
	"bytes"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/runtime"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// tomeProbe prints 1 when the project's Drupal has the module tome:static comes from.
const tomeProbe = `echo (int) \Drupal::moduleHandler()->moduleExists("tome_static");`

// exportCall is an export: the probe that refuses a Drupal without tome_static,
// then the export itself.
type exportCall struct {
	probe, run Command
}

// siteExport plans the Packaged site's export of the Site data c names. It
// addresses the static listener where the last start served it.
func siteExport(l Launch, c command, cwd string, e environ) (exportCall, error) {
	site, err := siteconfig.Load(l.Application)
	if err != nil {
		return exportCall{}, err
	}
	if site.Static == nil {
		return exportCall{}, errors.New("export needs a site whose composer.json sets extra.drupack.static")
	}
	if err := checkDatabase(c, e); err != nil {
		return exportCall{}, err
	}
	s, err := openSite(l, site, c, cwd, e)
	if err != nil {
		return exportCall{}, err
	}
	uri := s.staticURL
	if namesURI(c.words) {
		uri = ""
	}
	return exportCall{
		probe: s.drush(l, []string{"php:eval", tomeProbe}),
		run:   s.drush(l, exportWords(uri, c.words)),
	}, nil
}

// engineExport plans the engine's export of the project holding cwd. A project
// exported before its first start has no recorded URL, so the words must name one.
func engineExport(l Launch, c command, cwd string, e environ) (exportCall, error) {
	f, err := openFolder(l, cwd)
	if err != nil {
		return exportCall{}, err
	}
	if f.composer.Static == nil {
		return exportCall{}, fmt.Errorf("%s/composer.json sets no extra.drupack.static, so there is nothing to export.", runtime.Canonical(f.project))
	}
	uri := ""
	if !namesURI(c.words) {
		record, recorded, err := f.entry.Listener()
		if err != nil {
			return exportCall{}, err
		}
		if !recorded || record.StaticListen == "" {
			return exportCall{}, fmt.Errorf("Cannot read a recorded static URL for %s. Start it once, or pass --uri URL.", runtime.Canonical(f.project))
		}
		_, port, err := parseListen(record.StaticListen, "--static-listen")
		if err != nil {
			return exportCall{}, err
		}
		uri = siteURL(record.Scheme, record.Host, port)
	}
	run, err := f.drush(l, e, exportWords(uri, c.words))
	if err != nil {
		return exportCall{}, err
	}
	probe, err := f.drush(l, e, []string{"php:eval", tomeProbe})
	return exportCall{probe, run}, err
}

// exportWords are Drush's words for an export: Tome's export at uri, then the
// reader's. A reader who names the URL gets none added, and uri is "" then.
func exportWords(uri string, words []string) []string {
	command := []string{"tome:static", "--yes"}
	if uri != "" {
		command = append(command, "--uri="+uri)
	}
	return append(command, words...)
}

// namesURI reports whether the words name Drush's --uri, in its long, joined or
// -l forms, before any --.
func namesURI(words []string) bool {
	for _, word := range words {
		switch {
		case word == "--":
			return false
		case word == "--uri", strings.HasPrefix(word, "--uri="), strings.HasPrefix(word, "-l"):
			return true
		}
	}
	return false
}

// requireTomeStatic refuses an export from a Drupal without tome_static. A Drush
// that cannot bootstrap prints no answer, and the export's own run then reports
// the reason.
func (x exportCall) requireTomeStatic() error {
	var answer bytes.Buffer
	probe := &exec.Cmd{Path: x.probe.Path, Args: x.probe.Args, Env: x.probe.Env, Stdout: &answer}
	if err := probe.Run(); err != nil {
		var exit *exec.ExitError
		if !errors.As(err, &exit) {
			return errors.New("Cannot run Drush")
		}
	}
	if strings.TrimSpace(answer.String()) == "0" {
		return fmt.Errorf("export runs tome:static, and this Drupal has no tome_static module enabled. Enable it: %s drush pm:install tome_static", siteName)
	}
	return nil
}

// engineCore plans the engine's `dr`, the Drupal core command line a project
// ships from Drupal 11.4, in the project holding cwd.
func engineCore(l Launch, c command, cwd string, e environ) (Command, error) {
	start, err := filepath.EvalSymlinks(cwd)
	if err != nil {
		return Command{}, err
	}
	project, err := vendorRoot(start)
	if err != nil {
		return Command{}, err
	}
	script := filepath.Join(project, "vendor", "bin", "dr")
	if info, err := os.Stat(script); err != nil || !info.Mode().IsRegular() {
		return Command{}, fmt.Errorf("%s has no Drupal core command line: %s does not exist. Drupal 11.4 and later ship it.", runtime.Canonical(project), runtime.Canonical(script))
	}
	return runtimePHPCall(l, e, l.Application, append([]string{script}, c.words...)), nil
}

package main

import (
	"fmt"
	"path/filepath"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/cache"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// refusedComposerCommands are the Composer commands the engine refuses, with the
// refusal they give. %[1]s is the executable's name.
var refusedComposerCommands = []struct {
	names   []string
	refusal string
}{
	{[]string{"global"}, "%[1]s composer refuses global, which runs Composer outside this project."},
	{[]string{"create-project"}, "%[1]s composer refuses create-project, whose new directory would not hold %[1]s. Clone or download the project, then run %[1]s composer install in it."},
	{[]string{"self-update", "selfupdate"}, "%[1]s carries its own Composer. Run %[1]s self-update for a newer one."},
}

// engineComposer plans the engine's composer command: the engine's phar on the
// runtime's php-cli, in the Drupal project at cwd.
func engineComposer(f Facts, c command, cwd string) (Command, error) {
	if err := composerRefusal(f.Launch, c.words); err != nil {
		return Command{}, err
	}
	if err := siteconfig.CheckDrupal(cwd); err != nil {
		return Command{}, err
	}
	return runtimePHPCall(f, Reader, f.Application, append([]string{filepath.Join(f.Application, cache.ComposerPhar)}, c.words...)), nil
}

// composerRefusal refuses the words and the variable that point Composer at another
// project than the one CheckDrupal reads. The reader types both. Composer stops
// reading options at "--", and runs a command from any prefix of its name, in any case.
func composerRefusal(l Launch, words []string) error {
	// Composer reads composer.json when COMPOSER is empty or blank.
	if file, _ := l.lookup("COMPOSER"); strings.TrimSpace(file) != "" {
		return fmt.Errorf("%s composer refuses a set COMPOSER, which names another file than the project's composer.json. Unset it.", l.Name)
	}
	command := ""
	for _, word := range words {
		if word == "--" {
			break
		}
		switch {
		case word == "--working-dir", strings.HasPrefix(word, "--working-dir="),
			// A short option word holding d names the working directory: -d, -dDIR, or a
			// cluster such as -nd.
			!strings.HasPrefix(word, "--") && strings.HasPrefix(word, "-") && strings.Contains(word, "d"):
			return fmt.Errorf("%s composer runs in the working directory, and refuses %s. Run it from the project's root.", l.Name, word)
		case command == "" && !strings.HasPrefix(word, "-"):
			command = strings.ToLower(word)
		}
	}
	for _, refused := range refusedComposerCommands {
		for _, name := range refused.names {
			if command != "" && strings.HasPrefix(name, command) {
				return fmt.Errorf(refused.refusal, l.Name)
			}
		}
	}
	return nil
}

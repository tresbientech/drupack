package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/cache"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// refusedComposerCommands are the Composer commands the engine refuses, with the
// refusal they give. %[1]s is the word that runs the executable, %[2]s its name.
var refusedComposerCommands = []struct {
	names   []string
	refusal string
}{
	{[]string{"global"}, "%[1]s composer refuses global, which runs Composer outside this project."},
	{[]string{"self-update", "selfupdate"}, "%[2]s carries its own Composer. Run %[1]s self-update for a newer one."},
}

// engineComposer plans the engine's composer command: the engine's phar on the
// runtime's php-cli, in the Drupal project at cwd. A create-project first looks up
// its package, which must name a Drupal project.
func engineComposer(f Facts, c command, cwd string) (Command, error) {
	command, err := composerRefusal(f.Launch, c.words)
	if err != nil {
		return Command{}, err
	}
	// Composer runs create-project from any prefix of two letters or more; "c" is ambiguous.
	if len(command) >= 2 && strings.HasPrefix("create-project", command) {
		name, version, err := createProjectTarget(f.Launch, c.words)
		if err == nil {
			err = lookUpProjectPackage(f, name, version)
		}
		if err != nil {
			return Command{}, err
		}
	} else if err := siteconfig.CheckDrupal(cwd); err != nil {
		return Command{}, err
	}
	return runtimePHPCall(f, Reader, f.Application, append([]string{filepath.Join(f.Application, cache.ComposerPhar)}, c.words...)), nil
}

// composerRefusal refuses the words and the variable that point Composer at another
// project than the one CheckDrupal reads, and returns the command word in lower case.
// The reader types both. Composer stops reading options at "--", and runs a command
// from any prefix of its name, in any case.
func composerRefusal(l Launch, words []string) (string, error) {
	// Composer reads composer.json when COMPOSER is empty or blank.
	if file, _ := l.lookup("COMPOSER"); strings.TrimSpace(file) != "" {
		return "", fmt.Errorf("%s composer refuses a set COMPOSER, which names another file than the project's composer.json. Unset it.", l.Invoked)
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
			return "", fmt.Errorf("%s composer runs in the working directory, and refuses %s. Run it from the project's root.", l.Invoked, word)
		case command == "" && !strings.HasPrefix(word, "-"):
			command = strings.ToLower(word)
		}
	}
	for _, refused := range refusedComposerCommands {
		for _, name := range refused.names {
			if command != "" && strings.HasPrefix(name, command) {
				return "", fmt.Errorf(refused.refusal, l.Invoked, l.Name)
			}
		}
	}
	return command, nil
}

// createProjectValueOptions are create-project's options that take the next word as
// their value when it is not joined with "=".
var createProjectValueOptions = []string{"--stability", "--prefer-install", "--ignore-platform-req", "--require", "--audit-format"}

// createProjectTarget returns the package and version create-project installs, from
// create-project [options] [--] PACKAGE[:VERSION] [DIRECTORY [VERSION]]. Composer
// takes the VERSION word over the one joined to the package.
func createProjectTarget(l Launch, words []string) (name, version string, err error) {
	var positional []string
	command, options := false, true
	for i := 0; i < len(words); i++ {
		word := words[i]
		switch {
		case options && word == "--":
			options = false
		case options && (word == "--add-repository" || namesOption(word, "--repository") || namesOption(word, "--repository-url")):
			return "", "", fmt.Errorf("%s composer create-project refuses %s, which reads another repository than the Drupal check.", l.Invoked, word)
		case options && slices.Contains(createProjectValueOptions, word),
			// A short option word ending in s takes the stability next: -s dev, -ns dev.
			options && !strings.HasPrefix(word, "--") && strings.HasPrefix(word, "-") && strings.HasSuffix(word, "s"):
			i++
		case options && strings.HasPrefix(word, "-"):
		case !command:
			command = true
		default:
			positional = append(positional, word)
		}
	}
	if len(positional) == 0 {
		return "", "", fmt.Errorf("%s composer create-project needs a package, such as drupal/cms.", l.Invoked)
	}
	name = positional[0]
	if cut := strings.IndexAny(name, ":="); cut >= 0 {
		name, version = name[:cut], name[cut+1:]
	}
	if len(positional) > 2 {
		version = positional[2]
	}
	return name, version, nil
}

// namesOption reports word as the long option, alone or joined to its value with "=".
func namesOption(word, option string) bool {
	return word == option || strings.HasPrefix(word, option+"=")
}

// lookUpProjectPackage refuses a create-project package whose type and requirements name
// no Drupal project. composer show reads them, from an empty directory, in the
// repositories create-project reads.
func lookUpProjectPackage(f Facts, name, version string) error {
	empty, err := os.MkdirTemp("", "drupack-create-project-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(empty)
	// --working-dir keeps Composer from reading a parent directory's composer.json.
	words := []string{filepath.Join(f.Application, cache.ComposerPhar), "show", "--available", "--format=json", "--no-interaction", "--working-dir=" + empty, "--", name}
	if version != "" {
		words = append(words, version)
	}
	call := runtimePHPCall(f, Reader, f.Application, words)
	child := exec.Command(call.Path, call.Args[1:]...)
	child.Env, child.Dir = call.Env, empty
	var stderr bytes.Buffer
	child.Stderr = &stderr
	report, err := child.Output()
	var exit *exec.ExitError
	switch {
	case errors.As(err, &exit):
		return fmt.Errorf("composer show %s exited with status %d:\n%s", name, exit.ExitCode(), strings.TrimSpace(stderr.String()))
	case err != nil:
		return err
	}
	kind, require, err := packageFromShow(report)
	if err != nil {
		return fmt.Errorf("composer show %s: %w", name, err)
	}
	return siteconfig.CheckProjectPackage(name, kind, require)
}

// packageFromShow reads the type and requirements from composer show's JSON report,
// which a remote repository supplies. Composer leaves requires out of a package that
// has none.
func packageFromShow(report []byte) (kind string, require map[string]any, err error) {
	var fields struct {
		Type     *string        `json:"type"`
		Requires map[string]any `json:"requires"`
	}
	decoder := json.NewDecoder(bytes.NewReader(report))
	if err := decoder.Decode(&fields); err != nil {
		return "", nil, fmt.Errorf("the report is no JSON object: %w", err)
	}
	if decoder.More() {
		return "", nil, errors.New("the report holds more than one JSON value")
	}
	if fields.Type == nil {
		return "", nil, errors.New("the report names no type")
	}
	return *fields.Type, fields.Requires, nil
}

package build

import (
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/cache"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

const EngineName = "drupack"

// NewEnginePlan orders the steps that pack the engine executable for each of r.Platforms,
// the host's when it names none, one file per libc. A payload-only plan stops at the archive.
// It reads no site.
func NewEnginePlan(r Request) (Plan, error) {
	if len(r.Platforms) == 0 {
		r.Platforms = []string{r.Host}
	}
	// The engine reads no site, so it packs both files unless --libc names one.
	if r.Libc == "" {
		r.Libc = siteconfig.DefaultLibc
	}
	libcs, err := requestLibcs(r.Libc)
	if err != nil {
		return Plan{}, err
	}
	resolved, err := resolveRuntimes(r, libcs)
	if err != nil {
		return Plan{}, err
	}
	files := filepath.Join(r.Work, "engine")
	payload := filepath.Join(r.Work, "engine-payload")
	plan := Plan{Steps: []Step{
		{Name: "stage the engine files", Func: func(io.Writer) error { return stageEngine(r.Engine, r.Composer, files) }},
		{Name: "archive the engine files", Command: []string{"bash", filepath.Join(r.Engine, "build", "pack", "app-payload.sh"), files, payload}},
		{Name: "compress the engine files", Func: func(io.Writer) error { return compressPayload(payload) }},
		{Name: "record the Composer version", Func: func(io.Writer) error { return recordComposerVersion(r.PHP, r.Composer, payload) }},
	}}
	if r.PayloadOnly {
		plan.Steps = append(plan.Steps, Step{Name: "export the engine payload", Func: func(io.Writer) error {
			return copyInto(filepath.Join(r.Output, "payload"),
				filepath.Join(payload, PayloadName), filepath.Join(payload, ChecksumName), filepath.Join(payload, ComposerVersionName))
		}})
		return plan, nil
	}
	plan.Steps = append(plan.Steps, packSteps(r, libcs, resolved, "pack the engine executable for ", EngineName, payload, "-engine")...)
	return plan, nil
}

// engineFiles are the files of application/ the engine executable carries, relative to it.
var engineFiles = []string{"operate.php", "installed.php", "drush", "drush.cmd", "bin/php", "bin/php.cmd"}

// stageEngine copies engineFiles from the engine's application/ into files, and the
// composer phar beside them.
func stageEngine(engine, composer, files string) error {
	if err := os.RemoveAll(files); err != nil {
		return err
	}
	for _, name := range engineFiles {
		if err := copyFile(filepath.Join(engine, "application", filepath.FromSlash(name)), filepath.Join(files, filepath.FromSlash(name))); err != nil {
			return err
		}
	}
	return copyFile(composer, filepath.Join(files, cache.ComposerPhar))
}

// composerRelease reads the release from the first line of composer --version.
var composerRelease = regexp.MustCompile(`^Composer version (\S+)`)

// recordComposerVersion writes the release of the composer phar into the engine
// payload, so pack names it on --version without starting PHP.
func recordComposerVersion(php, composer, payload string) error {
	command := exec.Command(php, "php-cli", composer, "--version", "--no-ansi")
	command.Env = append(os.Environ(), "PHPRC="+filepath.Dir(php))
	out, err := command.Output()
	if err != nil {
		return fmt.Errorf("composer --version: %w", err)
	}
	release := composerRelease.FindSubmatch(out)
	if release == nil {
		return fmt.Errorf("composer --version names no release: %q", out)
	}
	return os.WriteFile(filepath.Join(payload, ComposerVersionName), release[1], 0o600)
}

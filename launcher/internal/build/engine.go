package build

import (
	"io"
	"os"
	"path/filepath"

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
		{Name: "stage the engine files", Func: func(io.Writer) error { return stageEngine(r.Engine, files) }},
		{Name: "archive the engine files", Command: []string{"bash", filepath.Join(r.Engine, "build", "pack", "app-payload.sh"), files, payload}},
		{Name: "compress the engine files", Func: func(io.Writer) error { return compressPayload(payload) }},
	}}
	if r.PayloadOnly {
		plan.Steps = append(plan.Steps, Step{Name: "export the engine payload", Func: func(io.Writer) error {
			return copyInto(filepath.Join(r.Output, "payload"),
				filepath.Join(payload, PayloadName), filepath.Join(payload, ChecksumName))
		}})
		return plan, nil
	}
	plan.Steps = append(plan.Steps, packSteps(r, libcs, resolved, "pack the engine executable for ", EngineName, payload, "-engine")...)
	return plan, nil
}

// engineFiles are the files of application/ the engine executable carries, relative to it.
var engineFiles = []string{"operate.php", "installed.php", "drush", "drush.cmd", "bin/php", "bin/php.cmd"}

// stageEngine copies engineFiles from the engine's application/ into files.
func stageEngine(engine, files string) error {
	if err := os.RemoveAll(files); err != nil {
		return err
	}
	for _, name := range engineFiles {
		if err := copyFile(filepath.Join(engine, "application", filepath.FromSlash(name)), filepath.Join(files, filepath.FromSlash(name))); err != nil {
			return err
		}
	}
	return nil
}

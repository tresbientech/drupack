package build

import (
	"io"
	"os"
	"path/filepath"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// EngineName names the engine executable, which serves a project folder and
// carries no site.
const EngineName = "drupack"

// NewEnginePlan orders the steps that pack the engine executable for each of
// r.Platforms, the host's when it names none, carrying both libcs' runtimes on each. A payload-only plan stops
// at the archive, in OUTPUT/payload. It reads no site, and the conformance
// suite tests the result beside a site's executable.
func NewEnginePlan(r Request) (Plan, error) {
	if len(r.Platforms) == 0 {
		r.Platforms = []string{r.Host}
	}
	libcs := siteconfig.Libcs["both"]
	resolved, err := resolveRuntimes(r, libcs)
	if err != nil {
		return Plan{}, err
	}
	files := filepath.Join(r.Work, "engine")
	payload := filepath.Join(r.Work, "engine-payload")
	plan := Plan{Steps: []Step{
		{Name: "stage the engine files", Func: func(io.Writer) error { return stageEngine(r.Engine, files) }},
		// The docroot argument names where the archive skips contrib sources, which
		// the engine's files have none of.
		{Name: "archive the engine files", Command: []string{"bash", filepath.Join(r.Engine, "build", "app-payload.sh"), files, payload, "web"}},
	}}
	if r.PayloadOnly {
		plan.Steps = append(plan.Steps, Step{Name: "export the engine payload", Func: func(io.Writer) error {
			return copyInto(filepath.Join(r.Output, "payload"),
				filepath.Join(payload, "app-payload.tar"), filepath.Join(payload, "app_checksum.txt"))
		}})
		return plan, nil
	}
	for _, platform := range r.Platforms {
		command := packCommand(r, libcs, resolved, platform, payload, Executable(EngineName, platform), "-engine")
		plan.Steps = append(plan.Steps, Step{Name: "pack the engine executable for " + platform, Command: command,
			Dir: filepath.Join(r.Engine, "launcher"), Env: []string{"CGO_ENABLED=0"}})
	}
	return plan, nil
}

// stageEngine copies engine/ into files. The files engine/ shares with the
// application are links into application/, which the copy follows.
func stageEngine(engine, files string) error {
	if err := os.RemoveAll(files); err != nil {
		return err
	}
	return copyTree(filepath.Join(engine, "engine"), files, func(string) bool { return false })
}

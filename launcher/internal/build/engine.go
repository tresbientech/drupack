package build

import (
	"io"
	"io/fs"
	"os"
	"path/filepath"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// EngineName names the engine executable, which serves a project folder and
// carries no site.
const EngineName = "drupack"

// NewEnginePlan orders the steps that pack the engine executable for each of
// r.Platforms, carrying both libcs' runtimes on each. It reads no site, and
// the conformance suite tests the result beside a site's executable.
func NewEnginePlan(r Request) (Plan, error) {
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
	for _, platform := range r.Platforms {
		command := []string{"go", "run", "./cmd/pack", "-engine"}
		for _, libc := range libcs {
			command = append(command, "-runtime", libc+"="+resolved[platform+"/"+libc])
		}
		command = append(command, "-entry", "drupack", "-version", r.EngineVersion,
			"-source", filepath.Join(r.Engine, "launcher"),
			"-output", filepath.Join(r.Output, Executable(EngineName, platform)),
			"-app", filepath.Join(payload, "app-payload.tar"), "-app-checksum", filepath.Join(payload, "app_checksum.txt"),
			"-goarch", siteconfig.Platforms[platform])
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
	source := filepath.Join(engine, "engine")
	return filepath.WalkDir(source, func(path string, entry fs.DirEntry, err error) error {
		if err != nil || entry.IsDir() {
			return err
		}
		relative, err := filepath.Rel(source, path)
		if err != nil {
			return err
		}
		return copyFile(path, filepath.Join(files, relative))
	})
}

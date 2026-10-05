// Package build turns a site and a set of targets into the ordered steps that
// produce its executables, and runs them.
package build

import (
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/node"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// Request is one build: the site, what to build it for, and where the tools are.
type Request struct {
	SiteDir string
	Site    siteconfig.Site
	// Platforms and Libc override the site's own, each when set.
	Platforms []string
	Libc      string
	// Runtimes maps "PLATFORM/LIBC" to a runtime directory.
	Runtimes map[string]string
	// RuntimeRoot holds a PLATFORM-LIBC directory per runtime it carries, and
	// supplies each target Runtimes leaves out. The job image sets it.
	RuntimeRoot string
	// Engine is the Drupack checkout or image copy holding application/, build/,
	// launcher/ and tests/.
	Engine   string
	PHP      string
	Composer string
	Output   string
	Work     string
	// Host is the platform this build runs on, the only one whose executable
	// the conformance suite can start.
	Host          string
	EngineVersion string
	SiteVersion   string
	// PayloadOnly stops after the application payload, for a build that packs
	// the payload elsewhere, such as macOS and Windows.
	PayloadOnly bool
}

// Step is one unit of a build: a command to run, or a function for the steps
// that only copy files, which writes what it reports to the build log.
type Step struct {
	Name    string
	Command []string
	Dir     string
	Env     []string
	Func    func(log io.Writer) error
}

// Plan is what a build runs, and which targets it packs without testing.
type Plan struct {
	Steps    []Step
	Untested []string
}

// Target names what one packed file runs on: its platform, with a -musl suffix
// for the musl runtime.
func Target(platform, libc string) string {
	if libc == "musl" {
		return platform + "-musl"
	}
	return platform
}

// Executable names a site's executable for one target.
func Executable(name, target string) string {
	return name + "-" + target
}

// NewPlan checks a request against what this release builds and orders its steps.
// The request comes from a site author's command line, so every refusal names
// the value it refuses.
func NewPlan(r Request) (Plan, error) {
	// The site's values passed its parser, so only a flag's can be refused below.
	if len(r.Platforms) == 0 {
		r.Platforms = r.Site.Platforms
	}
	if r.Libc == "" {
		r.Libc = r.Site.Libc
	}
	libcs, err := requestLibcs(r.Libc)
	if err != nil {
		return Plan{}, err
	}
	resolved, err := resolveRuntimes(r, libcs)
	if err != nil {
		return Plan{}, err
	}

	application := filepath.Join(r.Work, "app")
	payload := filepath.Join(r.Work, "payload")
	php := []string{r.PHP, "php-cli"}
	// The runtime reads php.ini beside itself only through PHPRC.
	phpEnv := []string{"PHPRC=" + filepath.Dir(r.PHP), "DRUPACK_CA_FILE=" + filepath.Join(r.Engine, "application", "cacert.pem")}
	install := append(append([]string{}, php...), r.Composer, "install", "--no-dev", "--prefer-dist", "--no-interaction", "--optimize-autoloader")
	// The build's PHP carries the engine list alone. The extension check and the
	// runtimes the site's executables carry answer for the site's additions.
	for _, extension := range r.Site.Extensions {
		install = append(install, "--ignore-platform-req=ext-"+extension)
	}
	plan := Plan{Steps: []Step{
		{Name: "check the PHP extensions", Command: []string{"python3", filepath.Join(r.Engine, "runtime", "check-extensions.py"),
			r.SiteDir, "--extensions=" + strings.Join(r.Site.Extensions, ",")}},
		{Name: "stage the site", Func: func(log io.Writer) error {
			if err := stageSite(log, r.SiteDir, application, r.Output, r.Work); err != nil {
				return err
			}
			// Staging copies what git tracks or would track, so an ignored settings file stays behind.
			if _, err := os.Stat(filepath.Join(application, r.Site.Settings)); r.Site.Settings != "" && err != nil {
				return fmt.Errorf("%s names settings %q, which the build did not copy: git ignores it", siteconfig.FileName, r.Site.Settings)
			}
			return nil
		}},
		{Name: "write site.json", Func: func(io.Writer) error { return siteconfig.Write(r.Site, application) }},
		{Name: "install the Composer project", Dir: application, Env: phpEnv, Command: install},
		{Name: "fetch translations", Env: append(phpEnv, "CURL_CA_BUNDLE="+filepath.Join(r.Engine, "application", "cacert.pem")),
			Command: append(append([]string{}, php...), filepath.Join(r.Engine, "build", "install-translations.php"), application)},
		{Name: "lay the engine over the site", Func: func(io.Writer) error { return layEngine(r.Engine, application, r.Site.Docroot) }},
	}}
	if r.Site.Node != "" {
		plan.Steps = slices.Insert(plan.Steps, 0, resolveNode(&r.Site, nodeTargets(r, libcs), r.Work))
	}
	// A site without a recipe ships no seed, and serves only a database that holds it.
	if r.Site.Recipe != "" {
		plan.Steps = append(plan.Steps, Step{Name: "install the seed site", Env: phpEnv,
			Command: []string{"bash", filepath.Join(r.Engine, "build", "seed.sh"), application, r.PHP, r.Site.Docroot, r.Site.Recipe, r.Site.Settings}})
	}
	plan.Steps = append(plan.Steps, Step{Name: "archive the application",
		Command: []string{"bash", filepath.Join(r.Engine, "build", "app-payload.sh"), application, payload, r.Site.Docroot}})
	if r.PayloadOnly {
		plan.Steps = append(plan.Steps, Step{Name: "export the payload", Func: func(io.Writer) error {
			return exportPayload(payload, application, filepath.Join(r.Output, "payload"), r.Site.Node != "", r.Work)
		}})
		return plan, nil
	}

	plan.Steps = append(plan.Steps, Step{Name: "write site.json beside the executables", Func: func(io.Writer) error {
		return siteconfig.Write(r.Site, r.Output)
	}})
	plan.Steps = append(plan.Steps, packSteps(r, libcs, resolved, "pack ", r.Site.Name, payload,
		"-site", filepath.Join(application, siteconfig.OutputName), "-site-version", r.SiteVersion)...)
	// The suite runs on the host's first file, the glibc one when the build packs both.
	tested := ""
	for _, platform := range r.Platforms {
		for _, libc := range libcs {
			target := Target(platform, libc)
			if tested != "" || platform != r.Host {
				plan.Untested = append(plan.Untested, target)
				continue
			}
			tested = target
		}
	}
	if tested != "" {
		command := []string{"python3", filepath.Join(r.Engine, "tests", "conformance"),
			filepath.Join(r.Output, Executable(r.Site.Name, tested)), filepath.Join(r.Work, "test-results")}
		if info, err := os.Stat(filepath.Join(r.SiteDir, "tests")); err == nil && info.IsDir() {
			command = append(command, "--site-tests", filepath.Join(r.SiteDir, "tests"))
		}
		plan.Steps = append(plan.Steps, Step{Name: "test " + tested, Command: command})
	}
	return plan, nil
}

// payloadTargets are the builds that pack an exported payload: the release
// workflow's Linux jobs, and the macOS and Windows builds on their own hosts.
var payloadTargets = []string{"linux-amd64", "linux-arm64", "macos-amd64", "macos-arm64", "windows-amd64"}

// nodeTargets lists the targets the resolve step fetches Node for: the payload
// targets for a payload-only build, otherwise each file the build packs.
func nodeTargets(r Request, libcs []string) []string {
	if r.PayloadOnly {
		return payloadTargets
	}
	var targets []string
	for _, platform := range r.Platforms {
		for _, libc := range libcs {
			targets = append(targets, Target(platform, libc))
		}
	}
	return targets
}

// nodeArchives is where the resolve step writes each target's Node archive.
func nodeArchives(work string) string {
	return filepath.Join(work, "node")
}

// resolveNode fetches the verified Node archive of each target into the work
// directory. It sets site.Node to the exact version, which the site.json steps
// after it write.
func resolveNode(site *siteconfig.Site, targets []string, work string) Step {
	return Step{Name: "resolve Node", Func: func(log io.Writer) error {
		resolved, err := node.Resolve(node.Request{Value: site.Node, Targets: targets, Dir: nodeArchives(work)})
		if err != nil {
			return err
		}
		site.Node = siteconfig.Node(resolved.Version)
		fmt.Fprintf(log, "Node %s\n", resolved.Version)
		return nil
	}}
}

// packSteps packs one file per platform and libc, each carrying its one runtime
// and the archive in payload, to OUTPUT/NAME-TARGET. extra adds the packer's
// flags for what the archive holds. A site asking for Node carries it on each
// target Node builds for.
func packSteps(r Request, libcs []string, resolved map[string]string, step, name, payload string, extra ...string) []Step {
	var steps []Step
	for _, platform := range r.Platforms {
		for _, libc := range libcs {
			target := Target(platform, libc)
			command := append([]string{"go", "run", "./cmd/pack", "-runtime", resolved[platform+"/"+libc],
				"-entry", "drupack", "-version", r.EngineVersion,
				"-source", filepath.Join(r.Engine, "launcher"),
				"-output", filepath.Join(r.Output, Executable(name, target)),
				"-app", filepath.Join(payload, "app-payload.tar"), "-app-checksum", filepath.Join(payload, "app_checksum.txt"),
				"-goarch", siteconfig.Platforms[platform]}, extra...)
			if libc == "musl" {
				command = append(command, "-musl")
			}
			if archive, carried := node.Archive(nodeArchives(r.Work), target); r.Site.Node != "" && carried {
				command = append(command, "-node", archive)
			}
			steps = append(steps, Step{Name: step + target, Command: command,
				Dir: filepath.Join(r.Engine, "launcher"), Env: []string{"CGO_ENABLED=0"}})
		}
	}
	return steps
}

// requestLibcs returns the C library of each Linux file libc packs.
func requestLibcs(libc string) ([]string, error) {
	libcs, ok := siteconfig.Libcs[libc]
	if !ok {
		return nil, fmt.Errorf("--libc %q is not one of both, glibc, musl", libc)
	}
	return libcs, nil
}

// resolveRuntimes checks each of r.Platforms and maps its "PLATFORM/LIBC" targets
// to runtime directories. A payload-only build packs nothing and maps none.
func resolveRuntimes(r Request, libcs []string) (map[string]string, error) {
	resolved := map[string]string{}
	for _, platform := range r.Platforms {
		if _, ok := siteconfig.Platforms[platform]; !ok {
			return nil, fmt.Errorf("--platform %q is not built by this release, which builds linux-amd64 and linux-arm64", platform)
		}
		if r.PayloadOnly {
			continue
		}
		for _, libc := range libcs {
			target := platform + "/" + libc
			if r.Runtimes[target] != "" {
				resolved[target] = r.Runtimes[target]
				continue
			}
			carried := filepath.Join(r.RuntimeRoot, platform+"-"+libc)
			if info, err := os.Stat(carried); r.RuntimeRoot != "" && err == nil && info.IsDir() {
				resolved[target] = carried
				continue
			}
			return nil, fmt.Errorf("no runtime for %s: pass --runtime %s=DIRECTORY", target, target)
		}
	}
	return resolved, nil
}

// Run executes the plan's steps in order, stopping at the first that fails.
func Run(plan Plan, log io.Writer) error {
	for _, step := range plan.Steps {
		fmt.Fprintf(log, "==> %s\n", step.Name)
		var err error
		if step.Func != nil {
			err = step.Func(log)
		} else {
			command := exec.Command(step.Command[0], step.Command[1:]...)
			command.Dir = step.Dir
			command.Env = append(os.Environ(), step.Env...)
			command.Stdout = log
			command.Stderr = log
			err = command.Run()
		}
		if err != nil {
			return fmt.Errorf("%s: %w", step.Name, err)
		}
	}
	for _, target := range plan.Untested {
		fmt.Fprintf(log, "Not tested: %s.\n", target)
	}
	return nil
}

// stageSite copies the site into the application directory. In a git checkout
// it copies what git tracks or would track, which leaves out the vendor, web
// and recipes directories a local composer install writes. It leaves out the
// build directories too, which may sit inside the site.
func stageSite(log io.Writer, site, application string, builds ...string) error {
	if err := os.RemoveAll(application); err != nil {
		return err
	}
	files, err := siteFiles(site)
	if err != nil {
		return err
	}
	root, err := filepath.EvalSymlinks(site)
	if err != nil {
		return err
	}
	for _, relative := range files {
		path := filepath.Join(site, relative)
		if slices.ContainsFunc(builds, func(directory string) bool { return within(path, directory) }) {
			continue
		}
		if err := stageEntry(log, root, path, filepath.Join(application, relative), relative); err != nil {
			return err
		}
	}
	return nil
}

// stageEntry copies one entry of the site. The executable's unpacker takes
// files and directories alone, so a link is copied as what it names when that
// lies inside the site, and is left out, named in the log, when it points out
// of the site or at nothing: its target could hold the build host's files.
func stageEntry(log io.Writer, root, path, destination, relative string) error {
	info, err := os.Lstat(path)
	if err != nil {
		return err
	}
	if info.Mode()&os.ModeSymlink != 0 {
		target, err := filepath.EvalSymlinks(path)
		if err != nil || !within(target, root) {
			link, _ := os.Readlink(path)
			fmt.Fprintf(log, "Left out %s, a link to %s, which is not in the site\n", relative, link)
			return nil
		}
		if within(path, target) {
			return fmt.Errorf("%s links to %s, a directory holding the link itself", relative, target)
		}
		path = target
		if info, err = os.Stat(path); err != nil {
			return err
		}
	}
	if !info.IsDir() {
		return copyFile(path, destination)
	}
	entries, err := os.ReadDir(path)
	if err != nil {
		return err
	}
	for _, entry := range entries {
		name := entry.Name()
		if err := stageEntry(log, root, filepath.Join(path, name), filepath.Join(destination, name), filepath.Join(relative, name)); err != nil {
			return err
		}
	}
	return nil
}

func within(path, directory string) bool {
	relative, err := filepath.Rel(directory, path)
	return err == nil && relative != ".." && !strings.HasPrefix(relative, ".."+string(filepath.Separator))
}

// siteFiles lists the site's files relative to it. Only a site outside any git
// checkout is walked: a walk of a checkout would copy .git, whose config can
// hold the CI's credentials, into the executable.
func siteFiles(site string) ([]string, error) {
	listing := exec.Command("git", "ls-files", "--cached", "--others", "--exclude-standard", "-z")
	listing.Dir = site
	out, err := listing.Output()
	if err == nil {
		var files []string
		for _, name := range strings.Split(strings.TrimRight(string(out), "\x00"), "\x00") {
			if name == "" {
				continue
			}
			// A tracked file deleted in the work tree has nothing to copy.
			if _, err := os.Lstat(filepath.Join(site, name)); err == nil {
				files = append(files, name)
			}
		}
		return files, nil
	}
	if inCheckout(site) {
		var stderr []byte
		if exit, ok := err.(*exec.ExitError); ok {
			stderr = exit.Stderr
		}
		return nil, fmt.Errorf("git ls-files in %s: %w: %s", site, err, strings.TrimSpace(string(stderr)))
	}
	var files []string
	err = filepath.WalkDir(site, func(path string, entry os.DirEntry, err error) error {
		if err != nil || entry.IsDir() {
			return err
		}
		relative, err := filepath.Rel(site, path)
		files = append(files, relative)
		return err
	})
	return files, err
}

// inCheckout reports whether directory or one above it holds a .git entry.
func inCheckout(directory string) bool {
	for {
		if _, err := os.Lstat(filepath.Join(directory, ".git")); err == nil {
			return true
		}
		parent := filepath.Dir(directory)
		if parent == directory {
			return false
		}
		directory = parent
	}
}

// layEngine copies the engine's application files over the site, which win over
// any file of the same name, then the site directory's settings and the
// installer's recipe catalog.
func layEngine(engine, application, docroot string) error {
	// The engine's own unit files and a developer's vendor link stay behind.
	err := CopyTree(filepath.Join(engine, "application"), application, func(relative string) bool {
		return strings.HasPrefix(relative, "tests"+string(filepath.Separator)) || relative == "vendor"
	})
	if err != nil {
		return err
	}
	sites := filepath.Join(application, docroot, "sites", "default")
	for source, destination := range map[string]string{
		"site-settings.php":  "settings.php",
		"site-templates.php": "site-templates.php",
	} {
		if err := copyFile(filepath.Join(engine, "build", source), filepath.Join(sites, destination)); err != nil {
			return err
		}
	}
	return nil
}

// exportPayload puts the payload and its site.json where a later platform build
// reads them, with each payload target's Node archive in node/ for a site
// carrying Node.
func exportPayload(payload, application, destination string, carriesNode bool, work string) error {
	err := copyInto(destination, filepath.Join(payload, "app-payload.tar"),
		filepath.Join(payload, "app_checksum.txt"), filepath.Join(application, siteconfig.OutputName))
	if err != nil || !carriesNode {
		return err
	}
	for _, target := range payloadTargets {
		archive, _ := node.Archive(nodeArchives(work), target)
		if err := copyInto(filepath.Join(destination, "node"), archive); err != nil {
			return err
		}
	}
	return nil
}

// copyInto copies each source file into destination under its own name.
func copyInto(destination string, sources ...string) error {
	for _, source := range sources {
		if err := copyFile(source, filepath.Join(destination, filepath.Base(source))); err != nil {
			return err
		}
	}
	return nil
}

// CopyTree copies every file under source to the same place under destination,
// following links, and leaves out each file skip names by its relative path.
func CopyTree(source, destination string, skip func(relative string) bool) error {
	return filepath.WalkDir(source, func(path string, entry os.DirEntry, err error) error {
		if err != nil || entry.IsDir() {
			return err
		}
		relative, err := filepath.Rel(source, path)
		if err != nil || skip(relative) {
			return err
		}
		return copyFile(path, filepath.Join(destination, relative))
	})
}

func copyFile(source, destination string) error {
	info, err := os.Stat(source)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(destination), 0o755); err != nil {
		return err
	}
	content, err := os.ReadFile(source)
	if err != nil {
		return err
	}
	return os.WriteFile(destination, content, info.Mode().Perm())
}

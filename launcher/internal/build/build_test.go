package build_test

import (
	"encoding/json"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/build"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

func request(platforms []string, libc string) build.Request {
	return build.Request{
		SiteDir: "/site",
		Site: siteconfig.Site{Name: "acme", Recipe: "recipes/acme", Docroot: "docroot",
			Platforms: []string{"linux-arm64"}, Libc: "musl"},
		Platforms: platforms,
		Libc:      libc,
		Runtimes: map[string]string{
			"linux-amd64/glibc": "/rt/amd64-glibc", "linux-amd64/musl": "/rt/amd64-musl",
			"linux-arm64/glibc": "/rt/arm64-glibc", "linux-arm64/musl": "/rt/arm64-musl",
		},
		Engine: "/engine", PHP: "/php/drupack", Composer: "/composer.phar",
		Output: "/out", Work: "/work", Host: "linux-amd64",
		EngineVersion: "0.3.0", SiteVersion: "1.4.0",
	}
}

func names(plan build.Plan) []string {
	var names []string
	for _, step := range plan.Steps {
		names = append(names, step.Name)
	}
	return names
}

func step(t *testing.T, plan build.Plan, name string) build.Step {
	t.Helper()
	for _, step := range plan.Steps {
		if step.Name == name {
			return step
		}
	}
	t.Fatalf("the plan has no step %q: %v", name, names(plan))
	return build.Step{}
}

// packed returns the runtime and the output of the pack step named name.
func packed(t *testing.T, plan build.Plan, name string) (string, string) {
	t.Helper()
	command := step(t, plan, name).Command
	return command[slices.Index(command, "-runtime")+1], command[slices.Index(command, "-output")+1]
}

// packSteps returns the names of the plan's pack steps, in order.
func packSteps(plan build.Plan) []string {
	var packs []string
	for _, name := range names(plan) {
		if strings.HasPrefix(name, "pack ") {
			packs = append(packs, name)
		}
	}
	return packs
}

func TestEachLibcPacksOneFilePerPlatform(t *testing.T) {
	cases := map[string][]string{
		"both":  {"pack linux-amd64", "pack linux-amd64-musl", "pack linux-arm64", "pack linux-arm64-musl"},
		"glibc": {"pack linux-amd64", "pack linux-arm64"},
		"musl":  {"pack linux-amd64-musl", "pack linux-arm64-musl"},
	}
	for libc, want := range cases {
		plan, err := build.NewPlan(request([]string{"linux-amd64", "linux-arm64"}, libc))
		if err != nil {
			t.Fatalf("%s: %v", libc, err)
		}
		if got := packSteps(plan); !reflect.DeepEqual(got, want) {
			t.Errorf("--libc %s packs %v; want %v", libc, got, want)
		}
	}
}

func TestEachFileCarriesTheRuntimeOfItsLibc(t *testing.T) {
	plan, err := build.NewPlan(request([]string{"linux-arm64"}, "both"))
	if err != nil {
		t.Fatal(err)
	}
	for name, want := range map[string][2]string{
		"pack linux-arm64":      {"/rt/arm64-glibc", filepath.Join("/out", "acme-linux-arm64")},
		"pack linux-arm64-musl": {"/rt/arm64-musl", filepath.Join("/out", "acme-linux-arm64-musl")},
	} {
		if runtime, output := packed(t, plan, name); runtime != want[0] || output != want[1] {
			t.Errorf("%s packs %s to %s; want %s to %s", name, runtime, output, want[0], want[1])
		}
	}
}

func TestEveryFileIsPackedAndOnlyTheHostGlibcFileIsTested(t *testing.T) {
	plan, err := build.NewPlan(request([]string{"linux-amd64", "linux-arm64"}, "both"))
	if err != nil {
		t.Fatal(err)
	}
	want := []string{
		"check the PHP extensions", "stage the site", "write site.json", "install the Composer project", "fetch translations",
		"lay the engine over the site", "install the seed site", "archive the application",
		"write site.json beside the executables", "pack linux-amd64", "pack linux-amd64-musl",
		"pack linux-arm64", "pack linux-arm64-musl", "test linux-amd64",
	}
	if got := names(plan); !reflect.DeepEqual(got, want) {
		t.Fatalf("steps = %v; want %v", got, want)
	}
	if want := []string{"linux-amd64-musl", "linux-arm64", "linux-arm64-musl"}; !reflect.DeepEqual(plan.Untested, want) {
		t.Fatalf("untested = %v; want %v", plan.Untested, want)
	}
	pack := strings.Join(step(t, plan, "pack linux-arm64").Command, " ")
	for _, want := range []string{
		"-goarch arm64", "-output " + filepath.Join("/out", "acme-linux-arm64"), "-site-version 1.4.0", "-version 0.3.0",
	} {
		if !strings.Contains(pack, want) {
			t.Errorf("the arm64 pack command lacks %q: %s", want, pack)
		}
	}
	test := step(t, plan, "test linux-amd64").Command
	if test[2] != filepath.Join("/out", "acme-linux-amd64") {
		t.Errorf("the suite runs %q; want the amd64 glibc executable", test[2])
	}
}

func TestAMuslBuildTestsTheHostMuslFile(t *testing.T) {
	plan, err := build.NewPlan(request([]string{"linux-amd64"}, "musl"))
	if err != nil {
		t.Fatal(err)
	}
	test := step(t, plan, "test linux-amd64-musl").Command
	if test[2] != filepath.Join("/out", "acme-linux-amd64-musl") {
		t.Errorf("the suite runs %q; want the amd64 musl executable", test[2])
	}
	if len(plan.Untested) != 0 {
		t.Errorf("untested = %v; want none", plan.Untested)
	}
}

func TestTheSiteTargetsApplyWhereNoFlagIsSet(t *testing.T) {
	plan, err := build.NewPlan(request(nil, ""))
	if err != nil {
		t.Fatal(err)
	}
	if got, want := packSteps(plan), []string{"pack linux-arm64-musl"}; !reflect.DeepEqual(got, want) {
		t.Errorf("packs %v; want the site's linux-arm64 musl file alone: %v", got, want)
	}
}

func TestFlagsBeatTheSiteTargets(t *testing.T) {
	plan, err := build.NewPlan(request([]string{"linux-amd64"}, "glibc"))
	if err != nil {
		t.Fatal(err)
	}
	if got, want := packSteps(plan), []string{"pack linux-amd64"}; !reflect.DeepEqual(got, want) {
		t.Errorf("packs %v; want the flags' linux-amd64 glibc file alone: %v", got, want)
	}
}

func TestTheSeedInstallsTheSiteRecipe(t *testing.T) {
	plan, err := build.NewPlan(request([]string{"linux-amd64"}, "both"))
	if err != nil {
		t.Fatal(err)
	}
	seed := step(t, plan, "install the seed site").Command
	if !slices.Contains(seed, "recipes/acme") {
		t.Fatalf("the seed command %v does not name the site's recipe", seed)
	}
}

func TestTheSiteScriptsTakeTheSiteDocroot(t *testing.T) {
	plan, err := build.NewPlan(request([]string{"linux-amd64"}, "both"))
	if err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"install the seed site", "archive the application"} {
		if command := step(t, plan, name).Command; !slices.Contains(command, "docroot") {
			t.Errorf("%s does not name the docroot: %v", name, command)
		}
	}
}

func TestASiteWithoutARecipeHasNoSeedStep(t *testing.T) {
	r := request([]string{"linux-amd64"}, "both")
	r.Site.Recipe = ""
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{
		"check the PHP extensions", "stage the site", "write site.json", "install the Composer project", "fetch translations",
		"lay the engine over the site", "archive the application",
		"write site.json beside the executables", "pack linux-amd64", "pack linux-amd64-musl", "test linux-amd64",
	}
	if got := names(plan); !reflect.DeepEqual(got, want) {
		t.Fatalf("steps = %v; want %v", got, want)
	}
}

func TestTheEngineLaysTheSettingsStubInTheDocroot(t *testing.T) {
	engine := t.TempDir()
	for name, content := range map[string]string{
		"application/launch.php": "<?php", "build/site-settings.php": "<?php // stub",
		"build/site-templates.php": "<?php // templates",
	} {
		path := filepath.Join(engine, name)
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	r := request([]string{"linux-amd64"}, "both")
	r.Engine, r.Work = engine, t.TempDir()
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	if err := step(t, plan, "lay the engine over the site").Func(io.Discard); err != nil {
		t.Fatal(err)
	}
	sites := filepath.Join(r.Work, "app", "docroot", "sites", "default")
	for name, want := range map[string]string{"settings.php": "<?php // stub", "site-templates.php": "<?php // templates"} {
		if content, err := os.ReadFile(filepath.Join(sites, name)); err != nil || string(content) != want {
			t.Errorf("%s = %q, %v; want %q", name, content, err, want)
		}
	}
}

func TestTheSuiteTakesTheSiteTestsWhenTheSiteHasThem(t *testing.T) {
	site := t.TempDir()
	if err := os.Mkdir(filepath.Join(site, "tests"), 0o755); err != nil {
		t.Fatal(err)
	}
	r := request([]string{"linux-amd64"}, "both")
	r.SiteDir = site
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	test := step(t, plan, "test linux-amd64").Command
	if got := strings.Join(test[len(test)-2:], " "); got != "--site-tests "+filepath.Join(site, "tests") {
		t.Fatalf("the suite command ends %q", got)
	}
}

func TestAPayloadOnlyBuildNeedsNoRuntimeAndPacksNothing(t *testing.T) {
	r := request([]string{"linux-amd64"}, "both")
	r.Runtimes = nil
	r.PayloadOnly = true
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	got := names(plan)
	if got[len(got)-1] != "export the payload" {
		t.Fatalf("a payload-only build ends with %q", got[len(got)-1])
	}
	for _, name := range got {
		if strings.HasPrefix(name, "pack ") || strings.HasPrefix(name, "test ") {
			t.Fatalf("a payload-only build runs %q", name)
		}
	}
}

func TestTheRuntimeRootSuppliesTheTargetsNoRuntimeNames(t *testing.T) {
	root := t.TempDir()
	if err := os.Mkdir(filepath.Join(root, "linux-amd64-musl"), 0o755); err != nil {
		t.Fatal(err)
	}
	r := request([]string{"linux-amd64"}, "both")
	r.RuntimeRoot = root
	delete(r.Runtimes, "linux-amd64/musl")
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	if got, _ := packed(t, plan, "pack linux-amd64"); got != "/rt/amd64-glibc" {
		t.Errorf("the glibc file packs %s; want the --runtime directory", got)
	}
	if got, _ := packed(t, plan, "pack linux-amd64-musl"); got != filepath.Join(root, "linux-amd64-musl") {
		t.Errorf("the musl file packs %s; want the root's directory", got)
	}

	delete(r.Runtimes, "linux-amd64/glibc")
	if _, err := build.NewPlan(r); err == nil || !strings.Contains(err.Error(), "--runtime linux-amd64/glibc=DIRECTORY") {
		t.Errorf("a root without linux-amd64-glibc: NewPlan error = %v; want one naming the missing runtime", err)
	}
}

func TestRefusalsNameTheValueTheyRefuse(t *testing.T) {
	cases := map[string]struct {
		request build.Request
		want    string
	}{
		"macOS":        {request([]string{"macos-arm64"}, "both"), `"macos-arm64"`},
		"Windows":      {request([]string{"linux-amd64", "windows-amd64"}, "both"), `"windows-amd64"`},
		"unknown libc": {request([]string{"linux-amd64"}, "gnu"), `"gnu"`},
		"missing runtime": {func() build.Request {
			r := request([]string{"linux-arm64"}, "glibc")
			delete(r.Runtimes, "linux-arm64/glibc")
			return r
		}(), "--runtime linux-arm64/glibc=DIRECTORY"},
	}
	for label, c := range cases {
		_, err := build.NewPlan(c.request)
		if err == nil || !strings.Contains(err.Error(), c.want) {
			t.Errorf("%s: NewPlan error = %v; want one naming %s", label, err, c.want)
		}
	}
}

func TestStagingAGitSiteLeavesOutWhatGitIgnores(t *testing.T) {
	site := t.TempDir()
	for name, content := range map[string]string{
		"composer.json": "{}", "drupack.yml": "name: acme\n", ".gitignore": "/vendor/\n",
		"vendor/autoload.php": "<?php", "recipes/acme/recipe.yml": "name: Acme\n",
	} {
		path := filepath.Join(site, name)
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if out, err := gitInit(site); err != nil {
		t.Skipf("git is unavailable: %v %s", err, out)
	}
	r := request([]string{"linux-amd64"}, "both")
	r.SiteDir = site
	r.Work = t.TempDir()
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	if err := step(t, plan, "stage the site").Func(io.Discard); err != nil {
		t.Fatal(err)
	}
	application := filepath.Join(r.Work, "app")
	if _, err := os.Stat(filepath.Join(application, "recipes", "acme", "recipe.yml")); err != nil {
		t.Errorf("the site's own recipe was not staged: %v", err)
	}
	if _, err := os.Stat(filepath.Join(application, "vendor")); !os.IsNotExist(err) {
		t.Errorf("an ignored vendor directory was staged")
	}
}

func TestStagingRefusesASettingsFileGitIgnores(t *testing.T) {
	site := t.TempDir()
	for name, content := range map[string]string{
		"composer.json": "{}", ".gitignore": "/acme.settings.php\n", "acme.settings.php": "<?php",
	} {
		if err := os.WriteFile(filepath.Join(site, name), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if out, err := gitInit(site); err != nil {
		t.Skipf("git is unavailable: %v %s", err, out)
	}
	r := request([]string{"linux-amd64"}, "both")
	r.SiteDir, r.Work, r.Site.Settings = site, t.TempDir(), "acme.settings.php"
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	if err := step(t, plan, "stage the site").Func(io.Discard); err == nil || !strings.Contains(err.Error(), `"acme.settings.php"`) {
		t.Fatalf("staging error = %v; want one naming the ignored settings file", err)
	}
}

func TestTheExtensionCheckTakesTheSiteAdditions(t *testing.T) {
	r := request([]string{"linux-amd64"}, "both")
	r.Site.Extensions = []string{"xmlwriter", "gmp"}
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	install := step(t, plan, "install the Composer project").Command
	for _, extension := range r.Site.Extensions {
		if !slices.Contains(install, "--ignore-platform-req=ext-"+extension) {
			t.Errorf("composer install %v does not skip the build PHP's check of %s", install, extension)
		}
	}
	check := step(t, plan, "check the PHP extensions").Command
	if got := strings.Join(check[len(check)-2:], " "); got != "/site --extensions=xmlwriter,gmp" {
		t.Fatalf("the check command ends %q", got)
	}
}

func TestTheSeedTakesTheSiteSettings(t *testing.T) {
	r := request([]string{"linux-amd64"}, "both")
	r.Site.Settings = "acme.settings.php"
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	if seed := step(t, plan, "install the seed site").Command; seed[len(seed)-1] != "acme.settings.php" {
		t.Fatalf("the seed command ends %q; want the site's settings file", seed[len(seed)-1])
	}
}

// linkedSite writes a site outside any git checkout holding the links a test names.
func linkedSite(t *testing.T, links map[string]string) string {
	t.Helper()
	site := t.TempDir()
	for name, content := range map[string]string{"composer.json": "{}", "shared/theme.css": "body {}"} {
		path := filepath.Join(site, name)
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	for name, target := range links {
		if err := os.Symlink(target, filepath.Join(site, name)); err != nil {
			t.Skipf("symlinks are unavailable: %v", err)
		}
	}
	return site
}

func TestStagingCopiesLinksInsideTheSiteAndLeavesOutTheRest(t *testing.T) {
	outside := filepath.Join(t.TempDir(), "secret.txt")
	if err := os.WriteFile(outside, []byte("host file"), 0o644); err != nil {
		t.Fatal(err)
	}
	r := request([]string{"linux-amd64"}, "both")
	r.SiteDir = linkedSite(t, map[string]string{
		"theme.css": "shared/theme.css", "assets": "shared", "secret.txt": outside, "gone.json": "/nowhere/gone.json",
	})
	r.Work = t.TempDir()
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	var log strings.Builder
	if err := step(t, plan, "stage the site").Func(&log); err != nil {
		t.Fatal(err)
	}
	application := filepath.Join(r.Work, "app")
	for _, name := range []string{"theme.css", filepath.Join("assets", "theme.css")} {
		info, err := os.Lstat(filepath.Join(application, name))
		if err != nil || !info.Mode().IsRegular() {
			t.Errorf("%s: %v, %v; want the linked file's content", name, info, err)
		}
	}
	for _, name := range []string{"secret.txt", "gone.json"} {
		if _, err := os.Lstat(filepath.Join(application, name)); !os.IsNotExist(err) {
			t.Errorf("%s was staged", name)
		}
		if !strings.Contains(log.String(), "Left out "+name) {
			t.Errorf("the log does not name %s: %q", name, log.String())
		}
	}
}

func TestStagingRefusesALinkToItsOwnDirectory(t *testing.T) {
	r := request([]string{"linux-amd64"}, "both")
	r.SiteDir = linkedSite(t, map[string]string{"loop": "."})
	r.Work = t.TempDir()
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	if err := step(t, plan, "stage the site").Func(io.Discard); err == nil || !strings.Contains(err.Error(), "loop") {
		t.Fatalf("staging error = %v; want one naming the loop", err)
	}
}

func TestStagingLeavesOutTheBuildsOwnDirectories(t *testing.T) {
	site := t.TempDir()
	for name, content := range map[string]string{
		"composer.json": "{}", "dist/acme-linux-amd64": "an earlier build", "work/app/composer.json": "{}",
	} {
		path := filepath.Join(site, name)
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if out, err := gitInit(site); err != nil {
		t.Skipf("git is unavailable: %v %s", err, out)
	}
	r := request([]string{"linux-amd64"}, "both")
	r.SiteDir = site
	r.Output = filepath.Join(site, "dist")
	r.Work = filepath.Join(t.TempDir(), "work")
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	if err := step(t, plan, "stage the site").Func(io.Discard); err != nil {
		t.Fatal(err)
	}
	application := filepath.Join(r.Work, "app")
	if _, err := os.Stat(filepath.Join(application, "composer.json")); err != nil {
		t.Errorf("the site's composer.json was not staged: %v", err)
	}
	if _, err := os.Stat(filepath.Join(application, "dist")); !os.IsNotExist(err) {
		t.Errorf("the output directory of an earlier build was staged")
	}
}

func TestStagingACheckoutGitCannotListFails(t *testing.T) {
	site := t.TempDir()
	if err := os.WriteFile(filepath.Join(site, "composer.json"), []byte("{}"), 0o644); err != nil {
		t.Fatal(err)
	}
	// A .git file that names no repository makes every git command in site fail.
	if err := os.WriteFile(filepath.Join(site, ".git"), []byte("not a gitdir\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	r := request([]string{"linux-amd64"}, "both")
	r.SiteDir = site
	r.Work = t.TempDir()
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	err = step(t, plan, "stage the site").Func(io.Discard)
	if err == nil || !strings.Contains(err.Error(), "git ls-files") {
		t.Fatalf("staging a checkout git cannot list returned %v", err)
	}
}

func gitInit(directory string) ([]byte, error) {
	command := exec.Command("git", "init", "-q")
	command.Dir = directory
	return command.CombinedOutput()
}

func TestTheEnginePlanPacksAFilePerLibcAndPlatformAndReadsNoSite(t *testing.T) {
	r := request([]string{"linux-amd64", "linux-arm64"}, "")
	r.Site = siteconfig.Site{}
	plan, err := build.NewEnginePlan(r)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{"stage the engine files", "archive the engine files",
		"pack the engine executable for linux-amd64", "pack the engine executable for linux-amd64-musl",
		"pack the engine executable for linux-arm64", "pack the engine executable for linux-arm64-musl"}
	if got := names(plan); !reflect.DeepEqual(got, want) {
		t.Fatalf("steps %v, want %v", got, want)
	}
	name := "pack the engine executable for linux-arm64-musl"
	if runtime, output := packed(t, plan, name); runtime != "/rt/arm64-musl" || output != filepath.Join("/out", "drupack-linux-arm64-musl") {
		t.Fatalf("%s packs %s to %s", name, runtime, output)
	}
	if !slices.Contains(step(t, plan, name).Command, "-engine") {
		t.Fatalf("pack command %v", step(t, plan, name).Command)
	}
}

func TestTheEnginePlanTakesTheLibcFlag(t *testing.T) {
	plan, err := build.NewEnginePlan(request([]string{"linux-amd64"}, "glibc"))
	if err != nil {
		t.Fatal(err)
	}
	if got, want := packSteps(plan), []string{"pack the engine executable for linux-amd64"}; !reflect.DeepEqual(got, want) {
		t.Fatalf("packs %v; want %v", got, want)
	}
}

func TestStagingTheEngineFollowsItsLinksIntoTheApplication(t *testing.T) {
	engine := t.TempDir()
	for path, content := range map[string]string{"application/process.php": "<?php // shared", "engine/serve.php": "<?php"} {
		if err := os.MkdirAll(filepath.Join(engine, filepath.Dir(path)), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(engine, path), []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.Symlink("../application/process.php", filepath.Join(engine, "engine", "process.php")); err != nil {
		t.Fatal(err)
	}
	r := request([]string{"linux-amd64"}, "")
	r.Engine, r.Work = engine, t.TempDir()
	plan, err := build.NewEnginePlan(r)
	if err != nil {
		t.Fatal(err)
	}
	if err := step(t, plan, "stage the engine files").Func(io.Discard); err != nil {
		t.Fatal(err)
	}
	staged := filepath.Join(r.Work, "engine", "process.php")
	info, err := os.Lstat(staged)
	if err != nil || info.Mode()&os.ModeSymlink != 0 {
		t.Fatalf("process.php staged as %v, %v; want a regular file", info, err)
	}
	if content, _ := os.ReadFile(staged); string(content) != "<?php // shared" {
		t.Fatalf("process.php holds %q", content)
	}
}

func TestAPayloadOnlyEnginePlanExportsTheArchiveAndPacksNothing(t *testing.T) {
	r := request([]string{"linux-amd64"}, "")
	r.Runtimes, r.PayloadOnly = nil, true
	plan, err := build.NewEnginePlan(r)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{"stage the engine files", "archive the engine files", "export the engine payload"}
	if got := names(plan); !reflect.DeepEqual(got, want) {
		t.Fatalf("steps %v, want %v", got, want)
	}
}

func TestASiteWithoutNodeWritesNoNodeEntry(t *testing.T) {
	r := request([]string{"linux-amd64"}, "both")
	r.Work = t.TempDir()
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	if slices.Contains(names(plan), "resolve Node") {
		t.Fatalf("steps = %v; want no Node step", names(plan))
	}
	// Staging makes the application directory.
	if err := os.Mkdir(filepath.Join(r.Work, "app"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := step(t, plan, "write site.json").Func(io.Discard); err != nil {
		t.Fatal(err)
	}
	content, err := os.ReadFile(filepath.Join(r.Work, "app", siteconfig.OutputName))
	if err != nil {
		t.Fatal(err)
	}
	var written map[string]any
	if err := json.Unmarshal(content, &written); err != nil {
		t.Fatal(err)
	}
	if node, ok := written["node"]; ok {
		t.Fatalf("site.json carries node %v", node)
	}
}

func TestASiteWithNodeResolvesItFirst(t *testing.T) {
	r := request([]string{"linux-amd64"}, "both")
	r.Site.Node = siteconfig.NodeLTS
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	if got := names(plan)[0]; got != "resolve Node" {
		t.Fatalf("the first step is %q; want resolve Node", got)
	}
}

func TestOnlyATargetNodeBuildsForCarriesIt(t *testing.T) {
	r := request([]string{"linux-amd64"}, "both")
	r.Site.Node = "24"
	plan, err := build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	glibc := step(t, plan, "pack linux-amd64").Command
	if index := slices.Index(glibc, "-node"); index < 0 || glibc[index+1] != filepath.Join("/work", "node", "linux-amd64.tar.gz") {
		t.Errorf("the glibc pack command %v does not carry the Node archive", glibc)
	}
	if musl := step(t, plan, "pack linux-amd64-musl").Command; slices.Contains(musl, "-node") {
		t.Errorf("the musl pack command %v carries Node", musl)
	}
	r.Site.Node = ""
	plan, err = build.NewPlan(r)
	if err != nil {
		t.Fatal(err)
	}
	if glibc := step(t, plan, "pack linux-amd64").Command; slices.Contains(glibc, "-node") {
		t.Errorf("a site without Node packs %v", glibc)
	}
}

package build_test

import (
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/build"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

func request(platforms []string, libc string) build.Request {
	return build.Request{
		SiteDir:   "/site",
		Site:      siteconfig.Site{Name: "acme", Recipe: "recipes/acme"},
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

// runtimeFlags returns the -runtime values a pack command carries, in order.
func runtimeFlags(command []string) []string {
	var values []string
	for index, argument := range command {
		if argument == "-runtime" {
			values = append(values, command[index+1])
		}
	}
	return values
}

func TestEachLibcPacksItsRuntimesInLauncherOrder(t *testing.T) {
	cases := map[string][]string{
		"both":  {"glibc=/rt/amd64-glibc", "musl=/rt/amd64-musl"},
		"glibc": {"glibc=/rt/amd64-glibc"},
		"musl":  {"musl=/rt/amd64-musl"},
	}
	for libc, want := range cases {
		plan, err := build.NewPlan(request([]string{"linux-amd64"}, libc))
		if err != nil {
			t.Fatalf("%s: %v", libc, err)
		}
		if got := runtimeFlags(step(t, plan, "pack linux-amd64").Command); !reflect.DeepEqual(got, want) {
			t.Errorf("--libc %s packs %v; want %v", libc, got, want)
		}
	}
}

func TestEveryPlatformIsPackedAndOnlyTheHostIsTested(t *testing.T) {
	plan, err := build.NewPlan(request([]string{"linux-amd64", "linux-arm64"}, "both"))
	if err != nil {
		t.Fatal(err)
	}
	want := []string{
		"stage the site", "write site.json", "install the Composer project", "fetch translations",
		"lay the engine over the site", "install the seed site", "archive the application",
		"write site.json beside the executables", "pack linux-amd64", "pack linux-arm64", "test linux-amd64",
	}
	if got := names(plan); !reflect.DeepEqual(got, want) {
		t.Fatalf("steps = %v; want %v", got, want)
	}
	if !reflect.DeepEqual(plan.Untested, []string{"linux-arm64"}) {
		t.Fatalf("untested = %v; want [linux-arm64]", plan.Untested)
	}
	pack := strings.Join(step(t, plan, "pack linux-arm64").Command, " ")
	for _, want := range []string{"-goarch arm64", "-output /out/acme-linux-arm64", "-site-version 1.4.0", "-version 0.3.0"} {
		if !strings.Contains(pack, want) {
			t.Errorf("the arm64 pack command lacks %q: %s", want, pack)
		}
	}
	test := step(t, plan, "test linux-amd64").Command
	if test[2] != "/out/acme-linux-amd64" {
		t.Errorf("the suite runs %q; want the amd64 executable", test[2])
	}
}

func TestTheSeedInstallsTheSiteRecipe(t *testing.T) {
	plan, err := build.NewPlan(request([]string{"linux-amd64"}, "both"))
	if err != nil {
		t.Fatal(err)
	}
	seed := step(t, plan, "install the seed site").Command
	if seed[len(seed)-1] != "recipes/acme" {
		t.Fatalf("the seed installs %q; want the site's recipe", seed[len(seed)-1])
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
	want := []string{"glibc=/rt/amd64-glibc", "musl=" + filepath.Join(root, "linux-amd64-musl")}
	if got := runtimeFlags(step(t, plan, "pack linux-amd64").Command); !reflect.DeepEqual(got, want) {
		t.Errorf("packs %v; want --runtime for glibc and the root for musl: %v", got, want)
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
		"no platform":  {request(nil, "both"), "--platform"},
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
	if err := step(t, plan, "stage the site").Func(); err != nil {
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
	if err := step(t, plan, "stage the site").Func(); err != nil {
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
	err = step(t, plan, "stage the site").Func()
	if err == nil || !strings.Contains(err.Error(), "git ls-files") {
		t.Fatalf("staging a checkout git cannot list returned %v", err)
	}
}

func gitInit(directory string) ([]byte, error) {
	command := exec.Command("git", "init", "-q")
	command.Dir = directory
	return command.CombinedOutput()
}

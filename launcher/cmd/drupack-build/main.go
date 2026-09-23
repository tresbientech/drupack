// Command drupack-build builds a site's executables from its composer project
// and drupack.yml, then runs the conformance suite on the one this host can run.
package main

import (
	"flag"
	"fmt"
	"os"
	"path/filepath"
	goruntime "runtime"
	"strings"

	"git.tresbien.tech/tresbientech/drupack/launcher/internal/build"
	"git.tresbien.tech/tresbientech/drupack/launcher/internal/siteconfig"
)

// runtimeFlags collects repeated --runtime PLATFORM/LIBC=DIRECTORY values.
type runtimeFlags map[string]string

func (f runtimeFlags) String() string { return fmt.Sprint(map[string]string(f)) }

func (f runtimeFlags) Set(value string) error {
	target, directory, found := strings.Cut(value, "=")
	if !found || !strings.Contains(target, "/") || directory == "" {
		return fmt.Errorf("--runtime takes PLATFORM/LIBC=DIRECTORY, got %q", value)
	}
	f[target] = directory
	return nil
}

func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func run() error {
	runtimes := runtimeFlags{}
	host := "linux-" + goruntime.GOARCH
	site := flag.String("site", "", "the site's directory, holding composer.json and drupack.yml")
	platforms := flag.String("platform", host, "comma-separated targets: linux-amd64, linux-arm64")
	libc := flag.String("libc", "both", "the C library of each Linux runtime: both, glibc or musl")
	flag.Var(runtimes, "runtime", "a runtime directory, as PLATFORM/LIBC=DIRECTORY, repeated")
	output := flag.String("output", "dist", "where the executables and site.json land")
	work := flag.String("work", "", "where the application is built, a new temporary directory when unset")
	siteVersion := flag.String("site-version", "dev", "the site's release, which --version prints")
	payloadOnly := flag.Bool("payload-only", false, "stop after the application payload, written to OUTPUT/payload")
	// The job image names its own copies of these in the environment.
	engine := flag.String("engine", os.Getenv("DRUPACK_ENGINE"), "the Drupack engine directory")
	engineVersion := flag.String("engine-version", os.Getenv("DRUPACK_ENGINE_VERSION"), "the engine's release")
	php := flag.String("php", os.Getenv("DRUPACK_PHP"), "the runtime executable the build runs PHP with")
	composer := flag.String("composer", os.Getenv("DRUPACK_COMPOSER"), "the composer.phar the build installs with")
	flag.Parse()
	for name, value := range map[string]string{
		"site": *site, "engine": *engine, "engine-version": *engineVersion, "php": *php, "composer": *composer,
	} {
		if value == "" {
			return fmt.Errorf("--%s is required", name)
		}
	}

	described, err := siteconfig.Read(*site)
	if err != nil {
		return err
	}
	if *work == "" {
		if *work, err = os.MkdirTemp("", "drupack-build-"); err != nil {
			return err
		}
	}
	request := build.Request{
		Site: described, Platforms: strings.Split(*platforms, ","), Libc: *libc, Runtimes: runtimes,
		Host: host, EngineVersion: *engineVersion, SiteVersion: *siteVersion, PayloadOnly: *payloadOnly,
	}
	// Every step runs in its own directory, so each path is made absolute once here.
	for target, path := range map[*string]string{
		&request.SiteDir: *site, &request.Engine: *engine, &request.PHP: *php, &request.Composer: *composer,
		&request.Output: *output, &request.Work: *work,
	} {
		if *target, err = filepath.Abs(path); err != nil {
			return err
		}
	}
	for target, path := range runtimes {
		if runtimes[target], err = filepath.Abs(path); err != nil {
			return err
		}
	}
	plan, err := build.NewPlan(request)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(request.Output, 0o755); err != nil {
		return err
	}
	return build.Run(plan, os.Stdout)
}

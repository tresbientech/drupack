# PRD: Drupack builds any recipe site

Source: `docs/rfc/0001-build-any-recipe-site.md`. This PRD supersedes the RFC in
three points: drupal.org GitLab is a supported CI, the build needs no Docker
daemon, and the build command is Go.

## Problem Statement

A Drupal site owner wants to hand their site to people as one executable, the way
Drupack hands out Mercury Demo. Today they would fork Drupack and edit five
places that name Mercury. They would then carry the launcher, the runtime build
and the release graph, and merge every Drupack fix by hand.

Their CI may be GitHub or drupal.org GitLab. drupal.org runners give no Docker
daemon, and the Drupack build needs one at three points.

They want to pick the target: one platform and one C library, or every platform.

## Solution

The site owner keeps a repository with their composer project, their recipe and a
`drupack.yml`. Their CI calls Drupack at a pinned engine tag and gets back tested
executables named after their site. On a tag, it can publish a release with
checksums, an SBOM and provenance.

GitHub callers use a reusable workflow. drupal.org GitLab callers include a CI
template from the `project/drupack` mirror. Any other CI clones the engine at a
tag and runs `drupack-build` inside the engine's job image.

The build runs without a Docker daemon. It downloads prebuilt runtimes that each
engine release publishes, builds the application with the job image's PHP, packs,
and runs the engine's conformance suite.

## User Stories

1. As a site owner, I want to describe my site in one `drupack.yml`, so that I edit no engine file.
2. As a site owner, I want to name my composer project's recipe as the site to install, so that my site ships with its own configuration and content.
3. As a site owner, I want my site's post-install steps to live in my recipe, so that one mechanism describes the site.
4. As a site owner, I want to set the default site name, so that a first start names the site after my product.
5. As a site owner, I want the executable, its download names and its cache directory to carry my site's name, so that end users see my product.
6. As a site owner, I want to set a default port, so that my site and another Drupack site run side by side without a flag.
7. As a site owner, I want to list the translations to bundle, so that my site starts in my users' languages offline.
8. As a site owner, I want a build to stop on a wrong `drupack.yml` field and name the field, so that I fix it without reading engine code.
9. As a site owner on GitHub, I want to call one reusable workflow pinned to an engine tag, so that an engine upgrade is one line.
10. As a site owner on drupal.org GitLab, I want to include one CI template from the drupal.org mirror, so that my project builds where it already lives.
11. As a site owner on another CI, I want one command after a `git clone` at a tag, so that I can build anywhere the job image runs.
12. As a site owner, I want to choose the platforms to build, so that I pay only for the targets I ship.
13. As a site owner, I want to choose glibc, musl or both on Linux, so that I can ship one small executable for a known host or one that runs everywhere.
14. As a site owner, I want each run to upload the executables as CI artifacts, so that I can try a build before releasing it.
15. As a site owner, I want a tag run to publish a release with checksums, an SBOM and provenance, so that my users can verify what they download.
16. As a site owner, I want the engine's conformance suite to run on my executable, so that an engine regression on my site stops my release.
17. As a site owner, I want to list extra paths the suite requests, so that my site's key pages are checked on every build.
18. As a site owner, I want a build on a runner without Docker to report which suite cases it skipped, so that I know what was not proven.
19. As a site owner, I want the MySQL and PostgreSQL cases to use my CI's database services, so that server-database support is proven without Docker.
20. As a site owner, I want a lock that declares an unsupported PHP extension to fail the build and name it, so that I learn the limit before my users do.
21. As a site owner, I want a default build to finish in minutes, so that runtime compilation never lands in my pipeline.
22. As an end user, I want `--version` to name the site version and the engine version, so that a bug report says what I ran.
23. As an end user, I want two sites built with Drupack to keep separate caches, so that cleaning one never breaks the other.
24. As an end user, I want every message and example command to name the executable I ran, so that I can copy and run it.
25. As an end user, I want `DRUPACK_*` variables to work on every Drupack-built site, so that one set of documentation covers them.
26. As an end user, I want both install paths to leave `automatic_updates` and `package_manager` uninstalled, so that cron never stalls and nothing writes into the read-only application.
27. As the Drupack maintainer, I want Mercury Demo to build through the same public entry points, so that every engine run exercises the contract callers use.
28. As the Drupack maintainer, I want the engine's own CI to build runtimes from source and hand them to `drupack-build`, so that an unreleased engine commit is tested before its runtimes are published.
29. As the Drupack maintainer, I want each engine release to publish the job image and one runtime archive per platform and libc, so that callers download instead of compile.
30. As the Drupack maintainer, I want one parser for `drupack.yml`, so that the contract has one definition.
31. As the Drupack maintainer, I want the build steps to exist once, in `drupack-build`, so that the Dockerfile and the CI wrappers never drift from it.
32. As the Drupack maintainer, I want the `mcp_tools` enable removed, so that the engine carries no site choice.
33. As a contributor, I want a local build to be one `docker run` of the job image, so that I reproduce CI on my machine.

## Implementation Decisions

Site contract:

- `drupack.yml` sits beside `composer.json`. Its fields are `name`, `port`, `recipe`, `site_name`, `languages` and `smoke_paths`.
- `name` is a Minted segment. `port` defaults to 7225. `languages` defaults to none. `smoke_paths` defaults to `/`.
- `extensions` is reserved. In this release a non-empty value fails the build.
- One Go package parses and validates `drupack.yml` and writes a normalized `site.json`. The launcher module takes one YAML dependency for it.
- `site.json` travels in the application payload and beside each executable. `launch.php`, the build and the suite read only `site.json`.

Build command:

- `drupack-build` is a Go command in the launcher module. It takes the site directory, platforms, libc, output directory and the site version.
- A pure planning function turns those inputs and the engine version into an ordered step list. A runner executes the steps as subprocesses.
- The steps are Composer install, the translation fetch, the seed install, the payload archive, the runtime fetch, the pack and the suite.
- The runtime fetch downloads one archive per platform and libc from the engine release and verifies it against the release checksums.
- An override takes a local runtime directory per libc. The engine's own CI uses it for commits that have no published runtimes.
- In this release only Linux platforms are accepted. A macOS or Windows platform fails with a message naming slice 2.

Job image and engine release:

- An engine tag publishes a job image holding the static PHP, Composer, Go, Python and `drupack-build`, pinned by digest.
- An engine tag publishes each runtime directory as an archive, plus a checksum file.
- The Dockerfile keeps only the runtime compile stages. The `app`, `packed` and `artifact` stages leave, with their CONTRIBUTING instructions.

Launcher and runtime identity:

- The packer takes `name`, `port`, the site version and the engine version and writes them into the launcher.
- The cache root is `<user cache>/<name>` and the temporary fallback is `<name>-<uid>`.
- The launcher exports the name and both versions to the runtime through `DRUPACK_RUNTIME_*` variables.
- `--version` prints the site name, the site version, the engine version and the libc.
- The entry point and `launch.php` name the executable in usage, readiness and error text.

Site behaviour:

- `launch.php` reads the recipe, the default site name and the default port from `site.json`.
- Both install paths keep the `automatic_updates` and `package_manager` uninstall.
- The `mcp_tools` enable leaves both install paths.

CI wrappers:

- A GitHub `workflow_call` workflow takes `platforms`, `libc` and `publish`. It runs `drupack-build` in the job image.
- A GitLab CI template in the engine repository, reached through the drupal.org mirror, takes the same three variables.
- Both upload the executables. With `publish`, both create a release with checksums, an SBOM and provenance on their forge.
- The engine's `release.yml` builds Mercury by calling the GitHub workflow with the local runtime override.

Repository layout:

- Engine PHP, the Caddyfile, `settings.php` and `support/` move out of `application/`.
- Mercury moves to `examples/mercury-demo/`, with a `drupack.yml` listing the five current languages.

## Testing Decisions

A good test drives a module through its public interface and asserts what a caller
sees: a returned value, an exit status, a file, a message. It never asserts a
private helper or a call order.

Tested modules:

- siteconfig: Go table tests over every field's valid and invalid values and the `site.json` output.
- build plan: Go table tests of the step list per platforms and libc combination, including refused platforms.
- runtime fetch: Go tests against an `httptest` server for a checksum mismatch, a missing archive and a reused cached copy.
- packer and launcher identity: the cache root follows `name`, two names keep two caches, and `--version` prints both versions.
- `launch.php` site settings: the defaults come from `site.json`, and messages name the executable.
- conformance suite: its site-specific cases read `site.json`, and a run without a Docker daemon reports its skipped cases.

Prior art:

- `launcher/internal/runtime/*_test.go` for Go unit tests
- `tests/conformance/launcher_cases.py` and `runtime_selection_cases.py` for packed fixture launchers
- `application/tests/launch_test.php` for `launch.php` units
- `tests/conformance/test_harness.py` for harness units

## Out of Scope

- macOS and Windows targets in `drupack-build`
- `extensions` additions and the caller's builder registry
- the version gate over both versions; the Version record keeps today's shape
- seeding a site from an existing database or files directory
- renaming the `DRUPACK_*` variables
- GitHub Actions and GitLab CI hosts other than github.com and git.drupalcode.org

## Further Notes

- Infrastructure issue #3265860 reports no Docker-in-Docker on drupal.org runners, and its page shows no resolution. The design assumes none.
- How the GitHub workflow finds its own engine ref stays open. The `github` context in a called workflow names the caller.
- The Mercury example may drop `drupal/mcp_tools` and `drupal/mcp_server` once nothing enables them. That is Mercury's decision, not the engine's.
- Engine tags become a public contract. A breaking change to `drupack.yml`, the CI inputs or `site.json` needs a major version.

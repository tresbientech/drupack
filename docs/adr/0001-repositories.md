# drupal.org holds the code, GitHub builds it, and the demo has its own repository

## Context

Drupack's code needs one place where merge requests land. Its executables need runners for Linux, macOS and Windows, which drupal.org does not offer. Drupacked Demo is a Packaged site with its own release rhythm, and it must not move the engine's version.

## Decision

### drupal.org

- `project/drupack` on drupal.org is canonical. `main` and the version tags live there, and `main` refuses a force push.
- A maintainer squash-merges a merge request on drupal.org.
- drupal.org's runners run the Go and Python unit tests on a merge request pipeline. A daily pipeline schedule starts `upstream.yml` and `release-node.yml` on GitHub. They build nothing.
- The issue queue holds open questions, follow-ups, plans and specs. A decided question lands as an ADR.

### GitHub

- `tresbientech/drupack` is a copy that builds, tests and publishes the release files. It takes no issues and no pull requests.
- A drupal.org webhook on push and tag events calls `repository_dispatch`. `sync.yml` clones drupal.org and pushes `main` and the tags to GitHub. It never forces and never prunes.
- Every push to GitHub uses an SSH deploy key. A push made with a job's own token starts no workflow.
- `upstream.yml` pushes `main` and the tag to drupal.org with a project access token, then to GitHub.
- A version tag starts `release.yml`, which tests the release files and publishes them as a GitHub Release.
- A manual dispatch builds every platform. A push to `main` builds Linux amd64 alone, when it touched a path an executable carries.
- GitHub documents no path filter for a tag push, and a tag whose build was filtered away would publish nothing.

### Drupacked Demo

- `tresbientech/drupacked-demo` is a Site repository. Its workflow calls `build.yml` at a Drupack tag.
- The demo takes its own version, `YYYY.MM.N`: the year, the month, and a counter that restarts at 1 each month.
- The demo's daily run bumps its Composer pins, its Node pin and the Drupack tag it names. Any move tags the demo.
- The daily run in this repository moves the runtime's pins and no site package. A Drupack tag comes from one of those moves or from a `version` dispatch.
- A Drupack release publishes no demo file. It publishes its macOS and Windows runtimes, which `build.yml` packs in native jobs.
- `build/release/demo-pin.sh` pins the demo commit and its release. `release.yml` and `build/test/qa.sh` build that commit and run its site tests.
- `.github/workflows/site-build.yml` builds that commit through `build.yml`, with its start cases. It runs after each successful `release.yml` push or weekly run on `main` whose payload job pushed a job image, and on dispatch. A release's own run does not wait for it.
- A candidate moves the pin to the demo's latest release only when that release descends from the pinned commit. A commit set by hand stays until a release contains it.
- `drupack.tresbien.tech/demo.sh` and `/demo.ps1` redirect to the install scripts of the demo's latest release.

## Reason

drupal.org carries the project page, the issue queue and the Packagist release of `drupal/drupack`, so a merge there is the real merge. GitHub has the macOS and Windows runners and the immutable Releases. A webhook starts the copy on each push, where a poll would list drupal.org's refs 288 times a day. GitHub's cron starts late under load, so drupal.org's pipeline schedule is the clock.

The demo has its own repository because on one tag line every demo bump is a Drupack release. Each such tag prompts every engine user through `self-update` and needs a drupal.org release node. With two lines, a demo reader sees no engine prompt, and an engine user sees no demo bump.

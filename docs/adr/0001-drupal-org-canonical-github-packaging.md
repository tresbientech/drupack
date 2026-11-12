# drupal.org is canonical, and GitHub builds and publishes

Drupack's canonical repository is the drupal.org general project `drupack`, at
`git.drupalcode.org/project/drupack`. The GitHub repository
`tresbientech/drupack` is a copy that builds, tests and publishes the release
executables. The Forge, git.tresbien.tech, was canonical until 2026-11-12 and
has left the release path.

## Context

The Forge force-pushed every branch to drupal.org. A merge request merged on
drupal.org was overwritten at the next push, so each one was squashed by hand
and closed unmerged. Nothing outside the LAN reaches the Forge, so no outside
event could start a Forge job.

## Decision

- `main` and the version tags are pushed to drupal.org. A merge request is
  squash-merged there. `main` refuses a force push.
- `.gitlab-ci.yml` gives drupal.org's runners two jobs and no build:
  - `tests` runs the Go and Python unit tests on a merge request pipeline. Its
    first run took 74 seconds.
  - `dispatch` runs on a daily pipeline schedule. It starts `upstream.yml` and
    `release-node.yml` on GitHub.
- A push to `main`, a tag and a docs-only merge request start no pipeline on
  drupal.org.
- A drupal.org webhook on push and tag events calls GitHub's
  `repository_dispatch`. `sync.yml` then clones drupal.org and pushes `main` and
  the tags to GitHub. It never forces and never prunes.
- Every push to GitHub uses an SSH deploy key, stored base64-encoded as the
  `MIRROR_DEPLOY_KEY_BASE64` secret. A secret is masked in logs only when it is
  one line. A push made with a job's own token starts no workflow.
- `upstream.yml` runs on GitHub, as
  [ADR 0029](0029-automatic-upstream-releases.md) describes. It pushes `main`
  and the tag to drupal.org with a project access token, the `DRUPAL_ORG_TOKEN`
  secret. The token has the Maintainer role and the `write_repository` scope
  only.
- GitHub takes no issues or pull requests.

## What GitHub builds

- A version tag such as `0.1.0` starts `release.yml`. It tests the release
  executables and publishes them as a GitHub Release.
- A manual dispatch builds every platform.
- A push to `main` builds Linux amd64 alone, and only when it touched a path
  outside `docs/`, `LICENSE` and the root Markdown files.
- That comparison lives in a `changes` job. GitHub documents a `paths-ignore`
  filter for a branch push and says nothing about a tag push. A tag whose build
  was filtered away would publish no release.

## The issue queue

The drupal.org project's
[issue queue](https://git.drupalcode.org/project/drupack/-/work_items) holds
Drupack's open questions, follow-ups, plans and specs.

- A plan lives whole in its issue description. The issue closes at merge.
- A decided question lands as a numbered ADR, and its issue closes with a link
  to it.
- An issue an LLM session files carries the `LLM` label and the template's AI
  disclosure. The `nod_bot` account files it after the maintainer approves the
  text.
- ADRs stay in the repository. A PRD is written in its issue.

## Considered options

- The Forge polls drupal.org every five minutes and pushes each change to
  GitHub. That is 288 ref listings a day on drupal.org, and the webhook needs
  none.
- The Forge keeps the release workflow and pushes to drupal.org. GitHub reaches
  drupal.org by itself, so the Forge would add a host and no check.
- The full build on drupal.org's runners. A release builds seven targets, and
  the macOS and Windows ones need GitHub's runners.
- GitHub's own cron as the daily clock. It starts late under load.

## Consequences

- drupal.org stores one GitHub fine-grained token twice: in the webhook's
  header and in the masked CI/CD variable `GITHUB_DISPATCH_TOKEN`. It has
  Contents and Actions write on `tresbientech/drupack`.
- Once that token expires, the sync and the daily runs stop. GitHub shows no
  failed run, since none starts.
- The release push to drupal.org fails once the project access token expires.
- A failed sync is a failed run in GitHub's Actions tab.
- A branch other than `main` stays on drupal.org.
- GitHub never lets a repository reuse the tag of an immutable release. Tags
  `0.1.1`, `0.1.2` and `0.1.5` stay on drupal.org only, and the sync excludes
  them. A tag with a published GitHub release can never move.
- GitLab's `changes` takes no negation, so the `tests` rule lists the code paths
  by name. A new top-level directory joins that list.
- The launcher's Go module path keeps the Forge's host name. No build fetches
  the module from that host.

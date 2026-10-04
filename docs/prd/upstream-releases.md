# PRD: Automatic releases on upstream versions

Source: design settled on 2026-10-04, after an interview and four competing
designs.

## Problem Statement

Drupack ships PHP, FrankenPHP, Node, Drupal core, Drupal CMS and contrib modules.
Each upstream release reaches readers only when the owner notices it, bumps the
pins by hand, runs QA and tags. On 2026-10-04 the demo was one PHP security
release behind (8.5.10 against 8.5.11, eleven GHSAs) and one Drupal core patch
behind. Nothing reported either.

## Solution

A daily GitHub Actions run checks every upstream. When one has a newer version
inside its current line, the run bumps the pins on top of `main`, builds and
tests every platform plus `build/qa.sh`, and on green pushes the commit and the
next version tag to the forge. The existing mirror and tag run then publish the
release. A held package or a new major is reported in the run summary and never
released.

## User Stories

1. As the owner, I want a PHP patch release shipped without my action, so that readers get security fixes within a day.
2. As the owner, I want Drupal core, Drupal CMS and contrib releases shipped the same way, so that the demo stays current.
3. As the owner, I want FrankenPHP and Node releases inside their lines shipped too, so that no bundled component lags.
4. As the owner, I want several upstream releases on one day to land in one release, so that tags stay few.
5. As the owner, I want a major version reported and never shipped, so that a breaking upgrade waits for me.
6. As the owner, I want to hold a package at a version with a written reason, so that a known breakage stays out.
7. As the owner, I want the hold's reason and the newer version printed on every run, so that a stale hold is visible.
8. As the owner, I want a red candidate to leave no tag, so that no immutable version is burned.
9. As the owner, I want a red candidate not rebuilt every day, so that runner minutes go to new versions only.
10. As the owner, I want to force a rerun of a red candidate, so that a flaky failure does not block a release.
11. As the owner, I want a dry run that shows the planned bump without pushing, so that I can check the bumper.
12. As the owner, I want the automatic tag to pass the same QA a hand tag passes, so that both releases mean the same thing.
13. As the owner, I want a moved `main` to stop the run instead of merging, so that the forge history stays linear.
14. As the owner, I want the bump commit to list each change, so that `git log` explains every automatic release.
15. As the owner, I want the run summary to remind me of the drupal.org release node, so that Packagist follows.
16. As the owner, I want to reproduce a run's bump locally with one command, so that I can debug the bumper.
17. As a reader, I want each release to carry the newest PHP of its line, so that my site runs a patched PHP.
18. As a reader, I want automatic releases numbered like hand releases, so that `drupack-install` and Composer treat them alike.
19. As a maintainer, I want each pin written by one tool, so that the Windows and Linux builds never disagree.

## Implementation Decisions

Bumper:

- D1. `build/upstream.py` takes no arguments. It reads the remote indexes,
  rewrites the pins in the working tree, and prints a Markdown summary. It
  never commits.
- D2. The summary has one row per source: current, found, action. Held packages
  print `HELD name current (newest): reason`. Majors print `MAJOR`.
- D3. Remote indexes and downloads are the trust boundary. A version that fails
  its pattern, or a missing hash, exits non-zero.
- D4. Each rewrite must match exactly one line, or the script exits non-zero.

Sources and lines:

- D5. PHP: newest 8.5.x from the php.net releases JSON. The bump waits until
  `downloads.php.net/~windows/releases/sha256sum.txt` lists both Windows zips.
- D6. FrankenPHP: newest 1.x GitHub release, its tag resolved to a commit.
- D7. Node: newest 24.x LTS from the nodejs.org `dist/index.json`.
- D8. Composer: each exact pin in the demo's `composer.json` moves to its newest
  release with the same major and equal or better stability. The order is
  alpha, beta, RC, stable.
- D9. `composer update --no-install` then moves the caret constraints and the
  lock, and `composer patches-relock` refreshes `patches.lock.json`.
- D10. A dev-branch pin with a commit is never rewritten.

Pins:

- D11. The script writes:
  - `runtime/builder-inputs.sh`: FrankenPHP version and commit, PHP version
  - `build/windows/build.ps1`: the same three, and both zip SHA-256s
  - the demo's `composer.json`: exact pins and `config.platform.php`
  - the demo's `composer.lock` and `patches.lock.json`
  - the demo's `drupack.yml`: `node`
- D12. `runtime/embed.sh` reads the FrankenPHP version from
  `builder-inputs.sh`. Its own copy goes.
- D13. The demo's `drupack.yml` pins Node exactly: `node: 24.21.0`.

Holds and majors:

- D14. Holds are a dict in `build/upstream.py`, package name to reason. It
  starts with two:
  - `twig/twig`: cron fails under 3.30 with a TypeError in easy_email's
    compiled template.
  - `drupal/ui_patterns`: commit `bac7f81` removes the `ui_patterns_source`
    formatter WordPal's Display Builder target uses.
- D15. A version outside the line (PHP 8.6, Drupal 12, FrankenPHP 2, Node 26)
  is printed as `MAJOR` in the run log. Nothing is written for it.

Workflow:

- D16. `.gitea/workflows/upstream.yml` runs daily on the forge runner, on cron
  and on `workflow_dispatch` with two inputs: `dry_run` and `force`. Nothing
  outside the LAN reaches the forge, so the job runs there.
- D17. No diff ends the run green. A diff gets a fingerprint, its SHA-256. The
  run name carries it.
- D18. A fingerprint whose earlier run failed is skipped, unless `force` is set.
- D19. The bump commit goes on `main` HEAD. Its body is the summary.
- D20. The next version bumps the last number of the highest tag:
  `1.0.0-alpha2` to `1.0.0-alpha3`, `1.0.0` to `1.0.1`.
- D21. The candidate branch `upstream/<version>` is pushed to the forge and to
  GitHub. The mirror's `--prune` then keeps it. Both copies are deleted at the
  end of the run.
- D22. The run dispatches `release.yml` on the candidate with `platform=all`, and
  waits for it with `gh run watch --exit-status`.
- D23. `release.yml` gains a `qa` job for an all-platform dispatch. It pulls both
  builder images by their `builder-tag.sh` digest, tags them `:local`, and runs
  `build/qa.sh`. Its result line goes to the step summary.
- D24. On green, one atomic push sends the commit to forge `main` as a
  fast-forward, plus the tag. A moved `main` rejects the push, and the run fails
  with no tag.
- D25. The mirror and the tag run of `release.yml` stay unchanged. They publish
  as they do for a hand tag.

Credentials and records:

- D26. One new forge secret, `RELEASE_DISPATCH_TOKEN`: a GitHub fine-grained
  token with Actions read and write on the repository. The owner creates it.
  The candidate branch reaches GitHub with `MIRROR_DEPLOY_KEY_BASE64`.
- D27. A new ADR records three points. CI green, the matrix plus `qa.sh`,
  replaces the local `qa-green` for automatic tags. The holds dict is the way
  to refuse an upstream. The drupal.org release node stays manual.
- D28. The summary ends with the drupal.org release node reminder, naming the tag.

## Considered options

- One manifest, `upstream.json`, read by bash, PowerShell and Python. The pin
  files lose their literals and every build needs `jq`.
- Renovate on the forge. It needs a Gitea bot token with broad scopes. Four of
  its behaviours went unverified. php-src tags precede the Windows zips, so
  glue was still needed.
- No pins: resolve at build time and publish a bill of materials. A tag could
  not be rebuilt from git alone, and the exact Node pin would be reversed.
- Releasing from the last tag plus the bump, on a release branch. It needs
  merge-backs into `main`.
- Skipping the run whenever `main` is ahead of the last tag.

## Testing Decisions

- A good test feeds the bumper recorded indexes and checks the rewritten files
  and the summary. It never reaches the network.
- Python unit tests for `build/upstream.py`, with fixture indexes:
  - the newest version inside each line is picked, and a major is reported
  - stability never goes backward, and alpha moves to beta
  - a held package is reported and left alone
  - PHP waits while a Windows zip is missing from `sha256sum.txt`
  - a rewrite matching zero or two lines fails
  - a bad version string from an index fails
  - the next-version arithmetic for a prerelease and a stable tag
- `actionlint` over both workflows.
- A `dry_run` dispatch of `upstream.yml` once it is on GitHub, and an
  all-platform dispatch of `release.yml` to prove the `qa` job.
- Prior art: `tests/conformance/test_app_payload.py` runs a build script
  against a fixture tree.

## Out of Scope

- Creating the drupal.org release node.
- Bumping the drupacked-demo example repository's `drupal/drupack` pin.
- Lifting a hold or shipping a major.
- Caddy apart from FrankenPHP, and SQLite apart from the builder image.
- The Dockerfile's tooling images and the GitHub Actions versions.
- Reusing the candidate's artifacts in the tag run.

## Further Notes

- A release costs two full matrices, about 67 minutes each, plus `qa.sh`. The
  candidate run spends the first, and the tag run the second.
- The first automatic release needs the deploy key secret, and a forge `main`
  that the key may push to.

# A daily run releases upstream versions on green CI

Accepted on 2026-10-04.

## Context

Drupack pins PHP, FrankenPHP, Node, Drupal core, Drupal CMS and contrib
modules. A new upstream version reached readers only when the owner noticed it,
bumped the pins, ran `build/qa.sh` and tagged. On 2026-10-04 the demo was one
PHP security release behind, and nothing had reported it.

## Decision

- `.github/workflows/upstream.yml` runs daily on GitHub. drupal.org's pipeline
  schedule dispatches it, as
  [ADR 0001](0001-drupal-org-canonical-github-packaging.md) describes.
- `build/upstream.py` moves each pin to its newest version inside its line:
  - PHP 8.5.x, once php.net lists both Windows zips
  - FrankenPHP 1.x, with the commit its tag names
  - the Windows vcpkg commit, set to the `builtin-baseline` of FrankenPHP's
    `vcpkg.json`, with the vcpkg-tool release that baseline names
  - `GO_VERSION` in `release.yml`, raised to the `go` line of FrankenPHP's
    `caddy/go.mod` when that line is newer
  - Node 24 LTS, which the demo now pins exactly
  - each exact Composer pin, within its major, at equal or better stability
- A newer major is printed as `MAJOR` in the run log and never written.
- `HOLDS` in `build/upstream.py` keeps a package at its pin. Each entry names
  the breakage a newer release causes, and every run prints it beside the
  newest version.
- The bump goes on `main` HEAD, on the candidate branch `upstream/<fingerprint>`
  that GitHub alone holds. The fingerprint is the SHA-256 of the bump's diff.
- The job dispatches `release.yml` on every platform through the GitHub API, to
  build and test the candidate. Its `qa` job runs `build/qa.sh`. The job finds
  the run by the candidate's commit and polls it.
- On green, one atomic push sends `main` as a fast-forward and the next version
  tag to drupal.org. A second push sends both to GitHub, where the tag run
  publishes. The job then deletes the candidate branch.
- A green candidate run replaces the local `qa-green` record for these tags.
- A bump whose candidate failed is not built again unless the dispatch sets
  `force`. A failed run on its branch name records the failure.
- The drupal.org release node stays a hand step. The run log names the tag it
  needs.

## Considered options

- The job on the forge's runner. It ran there while the forge was canonical,
  because nothing outside the LAN reaches the forge.
- One manifest read by bash, PowerShell and Python. The pin files lose their
  literals, and every build needs `jq`.
- Renovate on the forge. It needs a Gitea token with broad scopes, and php-src
  tags appear before php.net's Windows zips.
- Resolving versions at build time, with a bill of materials per release. A tag
  then cannot be rebuilt from git alone.

## Consequences

- A release costs two full matrices: the candidate run and the tag run.
- A moved `main` refuses the fast-forward. The run then fails with no tag, and
  the next day's run retries on the new `main`.
- The job's own token dispatches `release.yml`, reads its runs and reruns its
  failed jobs. Every push to GitHub uses the deploy key.
- The job holds one GitHub runner for the length of the candidate build.
- Anything merged on `main` ships with the next automatic tag.

## Amendment, 2026-10-13

A hand tag waited on a local `build/qa.sh` run and its `qa-green` record. The
record was checked by a hook that not every machine carried, and the run
covered Linux alone. `1.0.0-alpha4` failed its Windows job after such a tag.

- A release by hand is a dispatch of `upstream.yml` with `version`. It runs no
  bump, and the failed-candidate skip does not apply to it.
- Its candidate is `main` plus the guide's pin, on the branch
  `candidate/<version>`.
- `build/next-version.py` refuses a `version` without a tag's form, and one that
  does not sort above every tag.
- Every candidate commit rewrites the version `docs/build-your-site.md` names,
  through `build/pin-docs.py`. The fingerprint is taken before that rewrite.
- No person pushes a version tag, and no tag waits on a `qa-green` record.
  drupal.org still accepts such a push;
  [issue 3636023](https://git.drupalcode.org/project/drupack/-/work_items/3636023)
  holds that question.
- `.github/workflows/release-node.yml` runs each day with the upstream run. It
  fails while Packagist lists no `drupal/drupack` release for the highest tag.
- [ADR 0032](0032-drupacked-demo-own-repository.md) takes Drupacked Demo out of
  this run.

Consequences:

- A hand release costs two full matrices, as an automatic one does.
- The guide on `main` names a version before its tag run publishes it. A red tag
  run leaves that pin on an unpublished version until the next one ships.
- The node check is red on each day a tag waits for its node.

## Amendment, 2026-10-14

[ADR 0032](0032-drupacked-demo-own-repository.md) moved Drupacked Demo to its
own repository, with its bumper.

- The daily run here bumps PHP and FrankenPHP alone. The Node, Composer and
  `HOLDS` bumps run in the demo repository.
- Each candidate moves `build/demo-pin.sh` forward to the commit of the demo's
  latest release. A demo release alone tags nothing, because the run stops
  first when PHP and FrankenPHP stay put. The fingerprint covers the demo pin
  since the 2026-10-15 amendment of ADR 0032.

## Amendment, 2026-10-15

The 1.0.0-beta2 tag run failed on 2026-10-14 when proxy.golang.org dropped a
module download. No one saw it, and beta2 stayed unpublished.

- A `tag` job runs after `upstream`. It waits for the tag's `release.yml` run,
  reruns its failed jobs once, and fails if that rerun fails too.
- `build/wait-for-run.sh` finds and waits for a run, and reruns its failed jobs
  once.
  The candidate step and the `tag` job both call it.
- Every Go module fetch in CI gets a second attempt through `GOPROXY`.
- `RELEASE_PUBLISH_TOKEN`, a GitHub Actions secret, creates the GitHub Release.
  It is a fine-grained token on this repository with Contents and Workflows
  write. GitHub refuses `github.token` a release whose commit has other
  `.github/workflows` files than main. Main had moved by beta2's rerun.

Consequences:

- A tag run costs up to two runs of its failed jobs.
- A red tag run in the consequences above now means two failed attempts, and a
  red `tag` job reports it.

## Amendment, 2026-10-15: the tag run publishes the candidate

A tag run rebuilt and retested the commit its candidate run had built. Each
release took two full matrices, about three hours.

- The candidate dispatch passes `version`, so the candidate builds under the
  tag's version. Its run name is `Release candidate VERSION`.
- The tag run's gate looks for a successful dispatch run of that name at the
  tag's commit. When it finds one, every build job skips, and `publish`
  downloads that run's artifacts.
- The candidate pushes the job image as `sha-COMMIT` beside `dev-COMMIT`, so
  the tag run can name it after the version.
- A tag with no such run, or one whose artifacts expired after 90 days, builds
  every platform, as before.

Consequences:

- A release, automatic or by hand, costs one full matrix. This replaces the two
  matrices the consequences above name.
- A tag run with a candidate publishes its files, where a hand tag builds.
- The provenance attestation names the tag run, while the candidate run built
  the files from the same workflow at the same commit.

## Amendment, 2026-11-05: Windows PECL extensions

php_brotli 0.21.1 reached PECL on 2026-10-26. Linux and macOS took it at their
next build, since static-php-cli fetches the newest tag. The Windows pin stayed
at 0.21.0 until a hand bump on 2026-11-05.

- The daily run moves each `$peclExtensions` entry of `build/windows/build.ps1`
  to its newest stable PECL release inside its major.
- The bump waits until downloads.php.net has the zip for the pinned PHP minor
  and toolset.
- php.net lists no checksums for these zips. The run downloads the zip and pins
  its SHA-256.
- A newer major is printed as `MAJOR` and never written.

Consequences:

- A PECL release alone tags a Drupack version, as a PHP patch does.
- A zip replaced under the same name still fails the Windows build, as it did
  on 2026-10-26.

## Amendment, 2026-11-09: the candidate's QA is the release's QA

The upstream job waited for a candidate run to complete and said nothing until
then. Four failed runs completed 39 to 98 minutes after their first red job.
The owner's checkout still set `qa.command`, and a local `build/qa.sh` run
preceded the 1.0.0-beta15 candidate, whose `qa` job ran the same chain.

- `build/wait-for-run.sh` reads the run's jobs at each poll. It prints the name
  and link of each job that failed, timed out or was cancelled.
- The GitHub run goes on, so one attempt shows every red platform.
- The candidate's `qa` jobs are the release's QA. No local `build/qa.sh` run
  precedes a release, and the checkout sets no `qa.command`.
- `build/qa.sh` stays the chain a developer runs by hand, whole or one phase.

Consequences:

- The upstream job's log names a red job at the first poll after it ends. It polls
  every two minutes.

## Amendment, 2026-11-11: a failed candidate gets one rerun

On 2026-11-10 three candidate runs ended on a job that the change under test
did not touch:

- GitHub had no macOS arm64 runner for 15 minutes and cancelled the job.
- The first Node command of the Windows variant outlasted its 150 seconds.
- `docker rm` of a MySQL container outlasted 30 seconds in a class teardown.

The upstream job then ended at the first red job, so each release needed a new
dispatch and a whole new candidate.

- Every wait of `build/wait-for-run.sh` lasts until the run completes, then
  reruns the failed jobs once. GitHub reruns the jobs of a completed run alone.
- The candidate step and the `tag` job call it the same way.
- A failure the change caused fails the rerun too. The upstream job ends red after
  the run and the rerun.
- A cancelled candidate run gets its cancelled jobs rerun. Cancelling the
  upstream job stops a release.
- A container removal that outlasts its wait in a suite teardown prints a
  warning. The cases it served have their results by then.

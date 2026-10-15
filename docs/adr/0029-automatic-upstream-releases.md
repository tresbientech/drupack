# A daily run releases upstream versions on green CI

Accepted on 2026-10-04.

## Context

Drupack pins PHP, FrankenPHP, Node, Drupal core, Drupal CMS and contrib
modules. A new upstream version reached readers only when the owner noticed it,
bumped the pins, ran `build/qa.sh` and tagged. On 2026-10-04 the demo was one
PHP security release behind, and nothing had reported it.

## Decision

- `.gitea/workflows/upstream.yml` runs daily on the forge's runner. Nothing
  outside the LAN reaches the forge, so the job writes the forge with its own
  token and reaches GitHub outbound.
- `build/upstream.py` moves each pin to its newest version inside its line:
  - PHP 8.5.x, once php.net lists both Windows zips
  - FrankenPHP 1.x, with the commit its tag names
  - Node 24 LTS, which the demo now pins exactly
  - each exact Composer pin, within its major, at equal or better stability
- A newer major is printed as `MAJOR` in the run log and never written.
- `HOLDS` in `build/upstream.py` keeps a package at its pin. Each entry names
  the breakage a newer release causes, and every run prints it beside the
  newest version.
- The bump goes on `main` HEAD, on the candidate branch `upstream/<fingerprint>`
  that the forge and GitHub both hold. The fingerprint is the SHA-256 of the
  bump's diff. This amends [ADR 0001](0001-forge-canonical-github-packaging-mirror.md),
  which pushed only `main` and tags.
- The job dispatches `release.yml` on every platform through the GitHub API, to
  build and test the candidate. Its `qa` job runs `build/qa.sh`. The job finds
  the run by the candidate's commit and polls it.
- On green, one atomic push sends `main` as a fast-forward and the next version
  tag to the forge, and deletes the candidate branch. Gitea starts no workflow
  for a push made with the job's token, so the job then calls `mirror.yml`. The
  mirror and the tag run publish the tag as a hand tag.
- A green candidate run replaces the local `qa-green` record for these tags.
- A bump whose candidate failed is not built again unless the dispatch sets
  `force`. A failed run on its branch name records the failure.
- The drupal.org release node stays a hand step. The run log names the tag it
  needs.

## Considered options

- The job on GitHub Actions, with a forge deploy key. GitHub cannot reach the
  forge.
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
- `RELEASE_DISPATCH_TOKEN` on the forge holds a GitHub fine-grained token with
  Actions read and write on `tresbientech/drupack`. The candidate branch reaches
  GitHub with the mirror deploy key.
- The job holds one forge runner slot for the length of the candidate build.
- Anything merged on `main` ships with the next automatic tag.
- A push to `main` during a candidate build mirrors the candidate branch to
  GitHub and drupal.org. The next mirror run after its deletion prunes it.

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
- No person pushes a version tag, and no tag waits on a `qa-green` record. The
  forge still accepts such a push; `docs/backlog.md` holds that question.
- `.gitea/workflows/release-node.yml` runs each day before the upstream run. It
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

- A `tag` job runs after `mirror`. It waits for the tag's `release.yml` run,
  reruns its failed jobs once, and fails if that rerun fails too.
- `build/wait-for-run.sh` holds the wait, which the candidate step also calls.
- Every Go module fetch in CI gets a second attempt through `GOPROXY`.
- `RELEASE_PUBLISH_TOKEN`, a GitHub Actions secret, creates the GitHub Release.
  It is a fine-grained token on this repository with Contents and Workflows
  write. GitHub refuses `github.token` a release whose commit has other
  `.github/workflows` files than main. Main had moved by beta2's rerun.

Consequences:

- A tag run costs up to two runs of its failed jobs.
- A red tag run in the consequences above now means two failed attempts, and a
  red `tag` job on the forge reports it.

## Amendment, 2026-10-15: the tag run publishes the candidate

A tag run rebuilt and retested the commit its candidate run had built. Each
release took two full matrices, about three hours.

- The candidate dispatch passes `version`, so the candidate builds under the
  tag's version. Its run name is `Release candidate VERSION`.
- The tag run's gate looks for a successful dispatch run of that name at the
  tag's commit. When it finds one, every build job skips, and `publish`
  downloads that run's artifacts.
- The candidate's payload job pushes the job image as `sha-COMMIT` beside
  `dev-COMMIT`, so the tag run can name it after the version.
- A tag with no such run builds every platform, as before.

Consequences:

- A release costs one full matrix, which replaces the two the consequences
  above name.
- The provenance attestation names the tag run, while the candidate run built
  the files from the same workflow at the same commit.
- Artifacts expire after 90 days. A tag run started later than that finds the
  candidate run but no files, and fails.

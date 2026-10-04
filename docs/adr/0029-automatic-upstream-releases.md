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

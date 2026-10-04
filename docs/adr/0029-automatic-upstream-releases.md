# A daily run releases upstream versions on green CI

Accepted on 2026-10-04.

## Context

Drupack pins PHP, FrankenPHP, Node, Drupal core, Drupal CMS and contrib
modules. A new upstream version reached readers only when the owner noticed it,
bumped the pins, ran `build/qa.sh` and tagged. On 2026-10-04 the demo was one
PHP security release behind, and nothing had reported it.

## Decision

- `.github/workflows/upstream.yml` runs daily. `build/upstream.py` moves each
  pin to its newest version inside its line:
  - PHP 8.5.x, once php.net lists both Windows zips
  - FrankenPHP 1.x, with the commit its tag names
  - Node 24 LTS, which the demo now pins exactly
  - each exact Composer pin, within its major, at equal or better stability
- A newer major is printed as `MAJOR` in the run summary and never written.
- `HOLDS` in `build/upstream.py` keeps a package at its pin. Each entry names
  the breakage a newer release causes, and every run prints it beside the
  newest version.
- The bump goes on `main` HEAD, on a candidate branch the forge and GitHub
  both hold. An all-platform dispatch of `release.yml` builds and tests it.
  Its `qa` job runs `build/qa.sh`.
- On green, one atomic push sends `main` and the next version tag to the forge,
  as a fast-forward. The mirror and the tag run publish it as a hand tag.
- A green candidate run replaces the local `qa-green` record for these tags.
- A bump whose candidate failed is not built again unless the dispatch sets
  `force`.
- The drupal.org release node stays a hand step. The run summary names the tag
  it needs.

## Considered options

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
- `FORGE_DEPLOY_KEY_BASE64` on GitHub holds a forge deploy key with write
  access. The forge's RSA host key is pinned in the workflow.
- Anything merged on `main` ships with the next automatic tag.

# Drupacked Demo releases from its own repository

Accepted on 2026-10-13. All five steps of the order below were built by
2026-10-14. Steps 1 and 3 build [the native site builds PRD](../prd/native-site-builds.md).

## Context

One version tag publishes two products: the Engine executable and Drupacked
Demo. The daily run of [ADR 0029](0029-automatic-upstream-releases.md) tags on
any pin move.

- From 2026-10-07 to 2026-10-12 that run cut five tags. Each changed the demo's
  `composer.json` or `composer.lock`, and no engine file.
- Each tag gives `drupal/drupack` a version, which waits on a drupal.org release
  node created by hand. On 2026-10-13 Packagist listed none of the six tags
  after `1.0.0-alpha5`.
- Each tag prompts every engine user. The daily check of
  [ADR 0031](0031-the-engine-updates-itself.md) reads GitHub's `latest` release.
- A demo reader sees no prompt. A site executable refuses `self-update`.

## Decision

- Drupacked Demo moves to its own repository, canonical on the Forge and
  mirrored to GitHub. It is a Site repository: its workflow calls `build.yml` at
  a Drupack tag.
- The repository is new, `tresbientech/drupacked-demo`. The earlier example,
  `drupack-mercury-example`, is archived when the guide's link moves.
- The demo takes its own version, `YYYY.MM.N`: the year, the month, and a
  counter that starts at 1 each month. `2026.10.1` sorts above every demo
  version already installed.
- Each Drupack release publishes its macOS and Windows runtimes, and `build.yml`
  packs them in native jobs. The demo keeps its five targets.
- The demo's daily run bumps its Composer pins, its lock, its Node pin and the
  Drupack tag its workflow names. Any move tags the demo.
- The demo repository holds its bumper: the Composer, Node and hold code of
  `build/upstream.py`, and the bump of the Drupack tag.
- A demo tag follows a green candidate run of the demo's workflow on its five
  targets, as a Drupack tag follows one of `release.yml`.
- This repository's daily run bumps PHP and FrankenPHP alone. A Drupack tag
  comes from one of those moves, or from a `version` dispatch.
- A Drupack release publishes no demo file.
- `release.yml` and `build/qa.sh` build the demo repository at a tag this
  repository pins, and run its site tests. Each Drupack candidate moves that pin.
- `build.yml` takes a site's repository and ref as inputs. `release.yml` calls it
  with the demo repository at the pinned tag.
- `drupack.tresbien.tech/demo.sh` and `/demo.ps1` redirect to the `latest`
  release of the demo repository.
- `docs/build-your-site.md` names the demo repository as its worked example.
- Until the demo repository releases, the daily run here tags on any move.

## Order

Each step ships alone.

1. A Drupack release publishes its macOS and Windows runtimes.
2. The demo repository is created: Linux only, no schedule, no public link.
3. The native jobs of `build.yml` are written on a branch here. The demo
   repository, pinned to that branch, runs them.
4. A Drupack release carries those jobs. The demo lists five targets, turns its
   schedule on and releases. The redirection host and the guide's link move.
5. This repository builds the demo from its pinned tag and deletes
   `examples/drupacked-demo`. Its daily run then bumps PHP and FrankenPHP alone.

## Considered options

- Two tag lines in this repository. GitHub keeps one `latest` per repository,
  and shipped engines read `release.json` from it. The demo's install link
  would need a pointer kept elsewhere.
- One line, with demo bumps pushed to `main` untagged. A Drupal core fix then
  reaches a demo reader only when a Drupack tag follows.
- A demo built on `main`, with a Drupack tag cut whenever an engine path is
  untagged. Unfinished engine work would ship at 05:17 with any lock move.
- The Drupack tag checked out, with the demo directory from `main` laid over
  it. The workflow file from `main` would then drive scripts from the tag.
- A semver line for the demo. Two products would carry look-alike numbers.
- Renovate as the demo's bumper. It opens a pull request per bump and pushes no
  tag, so the forge would still run a job that merges and tags.
  [The site guide](../build-your-site.md#keeping-the-pins-current) gives it to
  site owners.
- The demo laid over `drupack-mercury-example`. The mirror's pruning push would
  delete its `0.1.x` tags and rewrite its `main`.
- The bumper kept here and carried by the job image. A bumper fix would wait on
  a Drupack tag before the demo got it.
- A demo tag pushed before any build. `main` would keep a bump that failed, and
  the next daily run would find no pin to move.
- No call of `build.yml` from this repository. A fault in it would first show
  in the demo's run, after its Drupack tag was frozen.
- A frozen copy of the demo here as the site under test. The two copies drift,
  and the engine is tested on an ageing Drupal.

## Consequences

- A demo release costs a site build with no PHP compile, and needs no
  drupal.org node.
- An engine user is prompted for an engine change alone.
- A PHP or FrankenPHP fix reaches a demo reader in two steps: the Drupack tag,
  then the demo's bump of the tag it names.
- The two repositories pin each other. An engine change that needs a demo
  change pins a demo branch commit here until both release.
- `build/qa.sh` fetches the demo repository.
- `examples/drupacked-demo` leaves this repository, with the Composer and Node
  bumps of `build/upstream.py` and the demo files of the publish job.
- `CONTEXT.md` defines Drupacked Demo as the Packaged site each Drupack release
  publishes. That entry changes when the demo repository first releases.
- The redirection host of INFRA ADR 0004 changes by hand, once.

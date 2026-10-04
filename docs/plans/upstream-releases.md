# Plan: Automatic releases on upstream versions

**Spec:** docs/prd/upstream-releases.md

## Architectural decisions

Durable decisions that apply across all phases, in the PRD's words:

- **D1**: `build/upstream.py` takes no arguments, rewrites the pins in the working tree, prints a Markdown summary, never commits.
- **D2**: One summary row per source; `HELD name current (newest): reason`; `MAJOR` lines.
- **D3**: Remote indexes and downloads are the trust boundary; a bad version or a missing hash exits non-zero.
- **D4**: Each rewrite matches exactly one line, or the script exits non-zero.
- **D5**: PHP: newest 8.5.x; the bump waits until `sha256sum.txt` lists both Windows zips.
- **D6**: FrankenPHP: newest 1.x release, tag resolved to a commit.
- **D7**: Node: newest 24.x LTS from `dist/index.json`.
- **D8**: Exact Composer pins move within their major to equal or better stability.
- **D9**: `composer update --no-install`, then `composer patches-relock`.
- **D10**: A dev-branch pin with a commit is never rewritten.
- **D11**: The pin files the script writes.
- **D12**: `runtime/embed.sh` reads the FrankenPHP version from `builder-inputs.sh`.
- **D13**: The demo pins `node: 24.21.0`.
- **D14**: Holds are a dict in the script: twig/twig and drupal/ui_patterns with their reasons.
- **D15**: A version outside the line is a `MAJOR` line and a workflow notice.
- **D16**: `upstream.yml`: daily cron and `workflow_dispatch` with `dry_run` and `force`.
- **D17**: No diff ends green; a diff's SHA-256 is its fingerprint, carried in the run name.
- **D18**: A fingerprint whose earlier run failed is skipped unless `force`.
- **D19**: The bump commit goes on `main` HEAD with the summary as body.
- **D20**: The next version bumps the last number of the highest tag.
- **D21**: Candidate branch `upstream/<version>` on the forge and GitHub, deleted at the end.
- **D22**: The run dispatches `release.yml` with `platform=all` and watches it.
- **D23**: `release.yml` gains a `qa` job for an all-platform dispatch, on pulled builder images.
- **D24**: On green, one atomic fast-forward push of `main` and the tag to the forge.
- **D25**: The mirror and the tag run stay unchanged.
- **D26**: Secret `FORGE_DEPLOY_KEY_BASE64`; forge host key pinned in the workflow.
- **D27**: A new ADR on automatic tags, holds and the manual release node.
- **D28**: The summary ends with the drupal.org release node reminder.

Boundaries:

- php.net releases JSON and `downloads.php.net/~windows/releases/sha256sum.txt`
- GitHub releases API for `php/frankenphp`
- nodejs.org `dist/index.json`
- packagist.org and packages.drupal.org/8 Composer metadata
- the forge over SSH on port 2222, with the write deploy key

---

## Phase 1: PHP bump in the bumper

**User stories**: 1, 16, 17, 19
**Decisions**: D1, D2, D3, D4, D5, D11

### What to build

The bumper with its first source. It reads the php.net index for the newest
8.5.x and the Windows checksums. It rewrites the PHP version in the builder
inputs, the Windows build (version and both hashes) and the demo's platform
PHP. It prints the summary and fails on a bad index or an ambiguous rewrite.
Unit tests feed it recorded indexes.

### Acceptance criteria

- [ ] `python3 -m unittest discover -s tests/upstream` passes, with cases for: a newer patch bumps all four PHP places; a missing Windows zip leaves PHP unchanged and says so; a bad version string fails; a rewrite matching zero or two lines fails.
- [ ] `python3 build/upstream.py` on the current tree exits 0, prints a PHP row, and `git status --porcelain` stays empty (8.5.11 is current).

---

## Phase 2: FrankenPHP and Node

**User stories**: 3, 19
**Decisions**: D6, D7, D11, D12, D13

### What to build

Two more sources. FrankenPHP's newest 1.x release rewrites the version and
commit in the builder inputs and the Windows build. Node's newest 24.x LTS
rewrites the demo's exact `node` pin, which this phase introduces. The runtime
link script reads the FrankenPHP version from the builder inputs.

### Acceptance criteria

- [ ] Unit tests: a FrankenPHP 1.x release rewrites version and commit in both files; a 2.0 release is a `MAJOR` line; a newer Node 24 LTS rewrites `node`; a Node 26 LTS is a `MAJOR` line.
- [ ] `git grep -n 'frankenphp_version=' runtime/` lists `builder-inputs.sh` alone.
- [ ] `bash runtime/builder-tag.sh gnu` prints the same tag before and after the phase.
- [ ] `flock /tmp/drupack-build.lock bash build/qa.sh` passes with `node: 24.21.0`.

---

## Phase 3: Composer, holds and majors

**User stories**: 2, 5, 6, 7
**Decisions**: D8, D9, D10, D14, D15

### What to build

The demo's exact Composer pins move to their newest release in the same major,
never to a lower stability. Composer then updates the lock without installing,
and composer-patches relocks. Dev-branch pins stay. The holds dict skips twig
and ui_patterns and prints each reason with the newer version.

### Acceptance criteria

- [ ] Unit tests: `1.0.0-alpha2` moves to `1.0.0-beta1` and never back to an alpha; `1.12.0` does not move to `2.0.0`, which is a `MAJOR` line; a held package prints `HELD` with its reason and stays; a `2.0.x-dev#<commit>` pin stays.
- [ ] `python3 build/upstream.py` on the current tree prints `HELD twig/twig 3.29.0 (3.30.0)` and the ui_patterns hold, and leaves composer.json unchanged when no other pin is behind.
- [ ] `composer validate --no-check-publish` in the demo passes after a run.

---

## Phase 4: QA on GitHub

**User stories**: 12
**Decisions**: D23

### What to build

An all-platform dispatch of the release workflow also runs the full local QA
on a GitHub Linux runner. The job pulls both builder images by their digest,
tags them as the Dockerfile's local defaults, frees disk space as the runtime
jobs do, runs the QA script, and writes its result line to the step summary.

### Acceptance criteria

- [ ] `actionlint .github/workflows/release.yml` reports nothing.
- [ ] After the branch reaches GitHub: `gh workflow run release.yml -R tresbientech/drupack --ref <branch> -f platform=all` ends with the `qa` job green and its result line in the summary.

---

## Phase 5: The daily workflow

**User stories**: 4, 8, 9, 10, 11, 13, 14, 15, 18
**Decisions**: D16, D17, D18, D19, D20, D21, D22, D24, D25, D26, D28

### What to build

The scheduled workflow. It runs the bumper and stops on no diff. It skips a
fingerprint that already failed unless forced. It commits on `main`, names the
next version, and pushes the candidate branch to the forge and GitHub. It
dispatches the release workflow and watches it. On green it pushes `main` and
the tag to the forge in one atomic push, deletes the candidate branches, and
reminds of the release node. A dry run stops after printing the diff.

### Acceptance criteria

- [ ] `actionlint .github/workflows/upstream.yml` reports nothing.
- [ ] Unit test: the next version of `1.0.0-alpha2` is `1.0.0-alpha3`, and of `1.0.0` is `1.0.1`.
- [ ] After merge and the secret: `gh workflow run upstream.yml -R tresbientech/drupack -f dry_run=true` ends green with the summary and no branch or tag created (`git ls-remote` on both remotes).

---

## Phase 6: Records

**User stories**: 6, 12, 15
**Decisions**: D27

### What to build

A new ADR for automatic upstream releases. CONTRIBUTING's release section names
the automatic path, how to add or lift a hold, and the manual release node.

### Acceptance criteria

- [ ] `ls docs/adr/0029-*.md` lists the ADR, and `grep -n 'qa-green\|hold\|release node' docs/adr/0029-*.md` finds each point.
- [ ] `grep -n 'upstream.py' CONTRIBUTING.md` finds the hold instructions.

---

## Deviations

- D9: the bumper runs no `composer patches-relock`. `patches.lock.json` records the patch definitions and each patch file's SHA-256, never a package version, so a pin bump leaves it unchanged. A patch that no longer applies still turns the candidate red, because the build applies it.
- D17, D21: the fingerprint lives in the candidate branch name, `upstream/<fingerprint>`, not in the run name or a version-named branch. A run name cannot carry a value computed after the run starts, and a run keeps its branch name after the branch is deleted, so `gh run list --branch` finds a failed candidate.
- D20: the next version comes from `build/next-version.py`, so the bumper keeps D1's no-argument interface.
- D21: the candidate branch is deleted by the same atomic push that sends `main` and the tag, and by the cleanup step on any other ending.
- D25: the tag run gains one step, the bumper's unit tests in the Linux job, as every build script's tests run there. `build/qa.sh` runs them too.

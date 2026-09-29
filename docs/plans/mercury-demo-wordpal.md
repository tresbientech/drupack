# Plan: WordPal in Mercury Demo

> Source PRD: `docs/prd/mercury-demo-wordpal.md`. Starts after the Bundled Node
> plan merges. Phases 1 to 3 stay on a branch until WordPal publishes a release.

## Architectural decisions

- `writable` lists the docroot's custom themes directory and the project's
  recipes directory, so the demo lays its own application in Site data.
- [x] `node: true`. The conversion runs on the bundled Node 24.21.0 with no
      Node on the host's PATH.
- The Site template becomes a site recipe that applies the Mercury Demo recipe
  and installs `wordpal_canvas`.
- Until WordPal publishes, an inline package repository names `drupal/wordpal`
  with the local WordPal checkout as its source, pinned to a commit.
- The first conversion applies WordPal's own recipe. The inline package
  scaffolds that recipe into `recipes/wordpal`, where the conversion looks.

---

## Phase 1: writable Mercury Demo

User stories: 9

### What to build

Mercury Demo's contract names the two writable directories. A fresh demo lays
the site's own application on its first start.

### Acceptance criteria

- [x] The writable cases pass against Mercury Demo.
- [x] Mercury Demo's site tests pass, with the site serving from its own
      application in Site data.

---

## Phase 2: WordPal converts on Linux

User stories: 1, 2, 3, 4, 5, 10

### What to build

The demo requires WordPal from the pinned local source and the two Drupal CMS
recipes it builds on. The site recipe installs `wordpal_canvas`, so a fresh
demo converts on its first command. The demo carries Bundled Node, which runs
Playground. A site test converts Twenty Twenty-Four and checks the result
across a restart.

### Acceptance criteria

- [x] `./mercury-demo drush wpc twentytwentyfour --target=canvas` exits 0 on a
      fresh demo.
- [x] The site test checks that `/` answers 200 with the converted theme's
      stylesheet, before and after a restart.
- [x] The site test skips by name when wordpress.org is unreachable.
- [x] A conversion from a theme zip path exits 0, run once by hand and
      recorded in the phase report.
- [x] `bash build/qa.sh` passes with the network present.
      Run as its own commands with the WordPal checkout mounted read-only at
      its path, which the container cannot otherwise reach.

---

## Phase 3: README

User stories: 6, 7

### What to build

The README shows the conversion command. It says conversion needs network
access to wordpress.org and npm, and the glibc build on Linux.

### Acceptance criteria

- [x] `grep -n 'wpc' README.md` finds the conversion command.
- [x] The README names wordpress.org, npm and the glibc build.

---

## Phase 4: published WordPal, macOS and Windows

User stories: 8, 11

### What to build

The demo takes WordPal from its published release, and the inline package
repository leaves `composer.json`. The first CI run proves conversion on macOS
and Windows. A WordPal fix that run proves needed goes to WordPal, and the
demo takes its next release. This phase is the merge gate.

### Acceptance criteria

- [ ] `composer.json` names no local path or inline WordPal package.
- [ ] The release workflow runs the conversion site test on Linux, macOS and
      Windows, and it passes.

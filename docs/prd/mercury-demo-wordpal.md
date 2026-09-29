# PRD: WordPal in Mercury Demo

Source: design settled on 2026-09-29. Needs the one runtime per Linux file PRD
and the Bundled Node PRD merged first.

## Problem Statement

A reader who wants to try WordPal today assembles a Drupal CMS project, clones
WordPal, links its recipe, installs Node and enables a module. Mercury Demo
already gives a working Drupal CMS site from one download, with no WordPal in
it.

## Solution

Mercury Demo carries WordPal, its converter enabled, and Bundled Node. A reader
runs one command to convert a WordPress theme, then opens the converted site:

```sh
./mercury-demo drush wpc twentytwentyfour --target=canvas
./mercury-demo
```

The converted theme and recipe land in the site's own application in Site data.

## User Stories

1. As a reader, I want `./mercury-demo drush wpc SLUG --target=canvas` to convert a WordPress theme, so that I try WordPal with one download.
2. As a reader, I want the converter enabled on a fresh demo, so that my first command converts.
3. As a reader, I want conversion to run on the demo's Bundled Node, so that I install nothing.
4. As a reader, I want the next start to serve the converted theme as the default theme, so that I see the result at once.
5. As a reader, I want a theme zip path or a zip URL accepted as well as a slug, so that I convert a theme outside wordpress.org.
6. As a reader, I want the README to say conversion needs network access to wordpress.org and npm, so that an offline failure is expected.
7. As a reader on a musl build, I want the README to say conversion needs the glibc build, so that I download the right file.
8. As a reader on Linux, macOS or Windows, I want conversion to work the same way, so that every platform can try WordPal.
9. As a reader who never converts, I want the demo to keep serving my site unchanged, so that WordPal costs me only the first start's application copy.
10. As a maintainer, I want a site test that converts and serves a theme, so that a regression stops the release.
11. As a maintainer, I want the demo to take WordPal from a published source before it merges, so that CI and releases can build it.

## Implementation Decisions

Site contract:

- `writable` lists the docroot's custom themes directory and the project's
  recipes directory. The demo then lays its own application in Site data, as
  ADR 0021 describes for any writable site.
- `node: true`.
- The Site template becomes a site recipe that applies the Mercury Demo recipe
  and installs `wordpal_canvas`.

Composer:

- Until WordPal publishes a release, an inline package repository names
  `drupal/wordpal` as a Drupal module. Its source is the local WordPal checkout,
  pinned to a commit.
- The two Drupal CMS recipes WordPal builds on join the demo's requirements.
- The first conversion applies WordPal's own recipe, so the seed needs no link
  to it.

Branch and merge:

- The work stays on a branch while WordPal is a local source. A local
  repository resolves only on the maintainer's machine, so CI cannot build it.
- Switching to a published WordPal is the merge gate. The first CI run after it
  must pass the conversion test on macOS and Windows.

## Testing Decisions

- A good test drives the executable the way a reader does and checks what the
  reader sees in the browser and the terminal.
- A site test in the demo's own test directory starts the demo and converts
  Twenty Twenty-Four. It checks that `/` answers 200 with the converted theme's
  stylesheet, then restarts the demo and checks the theme again.
- The test needs network access. It skips by name when the host cannot reach
  wordpress.org, and QA and CI run it with the network present.
- The branch proves the test on Linux. The first CI run after the switch proves
  it on macOS and Windows.
- Prior art: the demo's existing site tests and the writable directory cases.

## Out of Scope

- Changes to WordPal, apart from fixes the macOS or Windows run proves needed.
- The Display Builder target.
- A converter for the `drupack` Engine executable.
- Shipping WordPress Playground in the executable.

## Further Notes

- This PRD reverses the writable-directories PRD's line that kept Mercury Demo
  on the shared application.
- WordPal passes a host directory to Playground's mount option. On Windows the
  drive letter's colon may collide with that option's separator, which the
  Windows run will show.

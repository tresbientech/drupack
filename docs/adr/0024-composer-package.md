# A Composer package installs the engine executable into a project

Accepted on 2026-09-27.

## Context

A Drupal developer adds tools to a project with Composer, and `composer.lock`
pins each one per project. The engine executable is 90 MB per platform and
five platforms exist, so no package can carry the builds. Laravel Octane
installs FrankenPHP with an explicit `octane:install` command, which downloads
the latest build into the project root and adds it to `.gitignore`.

## Decision

- `tresbientech/drupack` on Packagist holds a root `composer.json` and one bin
  script, `vendor/bin/drupack-install`. `.gitattributes` keeps every other file
  out of the package.
- `drupack-install` reads the package's installed version, and
  `composer/semver` decides whether it names a release. It fetches that
  release's `install-drupack.sh`, or `install-drupack.ps1` on Windows, and runs
  it from a temporary file in the project root. The download and its SHA-256
  check stay in the install scripts.
- The version enters each URL encoded as one path segment, and reaches no
  command line.
- It adds `/drupack` to the project's `.gitignore`, and prints how to start it.
- A run whose `./drupack` matches a SHA-256 that the release's
  `checksums.txt` lists for `drupack` downloads nothing. `drupack-install`
  never runs that file, since a project can hold any file under the name.

## Considered options

- A Composer plugin downloading after every install. Composer asks whether to
  trust it, and a CI job installing dev dependencies downloads 90 MB.
- A bin script downloading on the first start. The first start waits on the
  download.
- `vendor/bin/drupack` as the target. The owner chose the project root, as
  Octane does.
- A PHP download against `checksums.txt`. It would copy the install scripts'
  host detection a third time.
- `drupal/drupack` on drupal.org. Its package facade needs release nodes, and
  serving general projects is unconfirmed.

## Consequences

- A package version installs only a release with the same tag. A `dev-main`
  install is refused.
- The first installable version is 0.5.1, since Packagist reads a tag only when
  its commit holds `composer.json`.
- After `composer update` changes the package version, `./drupack` stays at
  the old one until `drupack-install` runs again.
- On Linux and macOS the install script needs `curl` or `wget`, as the
  one-liner does.

## Amendment, 2026-09-27

`drupack-install` discards the install script's standard output and prints its
own lines. The script tells how to move the executable onto `PATH`, and a
project keeps it in its root. The script's errors still reach the terminal.

A project without `.gitignore` gets one when the project root or a parent
holds `.git`. Outside git, no `.gitignore` is created.

## Amendment, 2026-10-02

The package is `drupal/drupack`, published from the drupal.org general project
`drupack`. drupal.org staff confirmed that a general project release with a
valid `composer.json` reaches Packagist under the `drupal/` namespace.

- Each version tag needs a drupal.org release node, created by hand. The
  release workflow creates none.
- `drupal/drupack` starts at 1.0.0. Versions 0.5.1 to 0.9.0 stay on
  `tresbientech/drupack` and get no drupal.org release.
- Once 1.0.0 reaches Packagist, the Packagist package `tresbientech/drupack`
  is marked abandoned, with `drupal/drupack` as its replacement. The GitHub
  repository `tresbientech/drupack` stays: it builds every release and hosts
  the files `drupack-install` downloads.

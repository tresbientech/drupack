# PRD: A site writes into its own application

Source: `docs/adr/0021-writable-directories.md`.

## Problem Statement

A site owner has a Drush command that generates files the site then serves.
WordPal converts a WordPress theme into a Drupal theme and a recipe. The owner
wants one executable that runs the command and serves the result.

A Packaged site today reads one application copy per release. Every Site data
directory shares it and none writes to it. Drupal finds a theme only under its
root, so the generated theme has nowhere to land that Drupal and the web server
both see. A new release would also drop anything the site wrote.

## Solution

The site owner lists the directories the site writes in `drupack.yml`. An
executable built from that contract gives each Site data directory its own
application copy, laid on first start. The Drush command writes there through
`dr`, and the server serves from there.

A newer release lays its application beside the old copy and carries over the
entries the release does not ship. A site that lists nothing keeps the shared
copy.

## User Stories

1. As a site owner, I want to list the directories my site writes at runtime in `drupack.yml`, so that the executable keeps what lands there.
2. As a site owner, I want a build to reject a writable path that is absolute or leaves the project and name it, so that I fix it without reading engine code.
3. As a site owner, I want a site with no writable directory to keep the shared application, so that my other sites pay nothing for this feature.
4. As a site owner, I want each writable directory to exist on first start even when my project ships none of it, so that the command writes without creating it.
5. As an end user, I want `dr` to run the generating command with my own environment, so that tools on my PATH, such as Node, are found.
6. As an end user, I want a file the command writes under a writable directory served on the next request, so that no restart or extra route is needed.
7. As an end user, I want a theme the command writes found by Drupal, so that I can enable it with `dr`.
8. As an end user, I want the site's application to live in Site data, so that a second Site data directory of the same executable sees none of my writes.
9. As an end user, I want `clean` to leave the application in Site data alone, so that cleaning the cache never deletes my theme.
10. As an end user, I want a newer release to keep the theme and recipe an older release wrote, so that an upgrade loses nothing.
11. As an end user, I want an entry the new release ships to take the release's version on upgrade, so that fixed code is never shadowed by an old copy.
12. As an end user, I want an interrupted first start or upgrade to leave no half-laid application, so that the next start lays it again.
13. As an end user, I want the start to say it is laying the application and how far along it is, so that a 200 MB copy does not look like a hang.
14. As an end user, I want `dr` from a newer release than the laid application to refuse and name a start, so that Drush never runs on code the site has not upgraded to.
15. As an end user on Windows, I want the site copy to need no symlink privilege, so that it works on a plain account.
16. As an end user, I want the site copy laid with no network access, so that a first start works offline as before.
17. As a site owner, I want the conformance suite to prove a written theme is served before and after an upgrade, so that an engine regression stops my release.
18. As a site owner whose site lists no writable directory, I want that case reported as skipped by name, so that I know what was not proven.
19. As an engine maintainer, I want the site application layer tested without an executable, so that its upgrade rules are proven in seconds.
20. As a site owner, I want the CLI reference and the site-building guide to describe `writable`, so that I learn the field where the other fields are.

## Implementation Decisions

Contract:

- `writable` is a list of paths relative to the project root, under the same
  character rule as the other relative paths, with no absolute path, no `..`
  segment and no duplicate. It defaults to an empty list and is written to
  `site.json`.

Site application layer, one function in the launcher's runtime package:

- Inputs: the shared cache entry, the Site data directory, the writable list,
  the release's application checksum and a progress writer. Output: the site's
  application directory.
- It lays `app` in Site data by copying the shared entry, which the launcher
  has already unpacked to run the parser. A marker holding the checksum is
  written last. An entry with a matching marker is used as it stands.
- On another checksum it lays a staging copy beside `app`, moves into it every
  direct entry of each writable directory that the old copy holds and the new
  one lacks, then swaps and removes the old copy.
- Every writable directory exists in the laid copy.
- Progress goes to the writer as bytes copied against the total, as the unpack
  reports.

Launcher and parser:

- The launcher handles an internal word, `lay-app` with the Site data
  directory, before runtime selection, as it handles `clean`. It runs the layer
  and prints the directory. The CLI reference's "Other words" paragraph covers
  it: nothing there carries a promise.
- `launch.php` calls it once the options and the Serving lease are in hand and
  `site.json` lists a writable directory. It exports the site's application
  directory and docroot to every child it starts, and no longer names its own
  directory where the application belongs.
- `dr` takes the laid copy when its marker matches the release. Otherwise it
  refuses and names a start, since laying belongs to a start under the lease.
- The deployment identifier keeps its rule, hashed from the application path.

What shipped differs in five points, recorded on 2026-09-25 with the plan's
Status section:

- The lay extracts the executable's own payload rather than copying the
  shared entry.
- An upgrade copies entries out of the old copy, which stays whole until the
  swap.
- `lay-app` prints nothing on standard output. `launch.php` names the
  directory itself.
- The conformance case writes the probe theme with the test's own file writes,
  and fakes an upgrade by rewriting the release marker.
- The deployment identifier also hashes the release marker.

## Testing Decisions

A good test drives a public surface and asserts what a user observes: a
rejected field named in the error, a file present after a lay, a page served.
It never reads the layer's private state beyond the marker.

- Contract parser: Go unit tests beside the existing parser cases. Accepted
  list, rejected absolute path, rejected `..`, rejected duplicate, empty
  default.
- Site application layer: Go unit tests over temporary directories, beside the
  application cache tests. First lay, reuse on the same checksum, new checksum
  with carry-over, shipped entry wins, interrupted lay leaves no marker,
  writable directory created when absent.
- Conformance case, beside the replacement cases: write a probe theme into the
  writable directory through `php-cli`, enable it with `dr`, fetch a page that
  renders it, rebuild the executable as the replacement cases do, start again,
  fetch the page again. It skips by name when `site.json` lists no writable
  directory, as the seed cases skip on a site without a recipe.
- `launch.php` wiring: no new test. The existing option and help assertions
  stay green, and the conformance case covers the wiring on a site that uses
  it.

## Out of Scope

- The converter site project, its recipe, and any change to WordPal.
- Detecting Node or any other host tool. The site's documentation names it.
- A writable directory on the shared application, or sharing a laid copy
  between two Site data directories.
- Moving Mercury Demo to a per-site copy.
- Removing an old laid copy from Site data by any command.

## Further Notes

- The ADR records why a link farm and writes into the shared root lost.
- The converter site is built after this ships, in its own repository beside
  WordPal, with `writable: [web/themes/custom, recipes]`.

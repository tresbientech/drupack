# The release run posts the drupal.org release form

Accepted on 2026-11-12. Amends [ADR 0024](0024-composer-package.md) and
[ADR 0029](0029-automatic-upstream-releases.md).

## Context

Packagist takes each `drupal/drupack` version from a drupal.org release node.
drupal.org has no API that creates one. A person filled the release form for
each tag, and releases 1.0.0-beta16 to beta19 went out with no notes. The daily
upstream run tags with nobody watching, so a version could wait a day.

drupal.org staff accept the form post by plain HTTP for limited use. The
request carries a session cookie and a user agent that names the tool. It does
not imitate a browser. Drupal CMS creates its releases this way, from a
maintainer's machine.

## Decision

- The `node` job of `upstream.yml` posts the release form as `nod_bot`. It
  starts once the tag's release run has published the executables.
- `build/release-node.py` sends the requests with Python's standard library,
  under the user agent `drupack-release`.
- The form has two steps. The first picks the tag and saves nothing. The second
  takes the notes and the release type, and saves.
- The script sends back every field the form holds, as a browser does, and sets
  three: the tag, the notes and the release type.
- The `notes` job writes the notes with `drupalorg-cli`. The release type
  follows their headings: Bug gives "Bug fixes", Feature gives "New features".
  The script never ticks "Security update".
- The `DRUPAL_ORG_SESSION` secret holds the `Cookie` request header of a
  browser logged in as `nod_bot` on `www.drupal.org`. The session cookie alone
  opens no session.
- The cookie goes to `www.drupal.org` only. The script follows a redirect
  inside that site and stops on one that leaves it, such as the login's.
- The `node` job runs this repository's code alone. `drupalorg-cli` runs in the
  `notes` job, which holds no secret, and the notes cross as a job output.
- The form's first step offers the tags that have no release. For a tag it
  does not offer, the script posts nothing and reads the release page.
- The script reads the release page after the post, and fails without it.

## Considered options

- A browser driven by Playwright or Selenium. Staff advise against it, and
  browser-like traffic is what their bot filter stops.
- A login with `nod_bot`'s password on each run. The login is single sign-on
  on `accounts.drupal.org`, so it means a scripted OpenID Connect exchange.
- The maintainer's own account. A cookie that acts as a person on all of
  drupal.org would sit in a CI secret.
- A post right after the tag push. A tag run that then fails would leave a
  Packagist version with no executables behind it.
- drupal.org's JSON:API. Staff name it for after releases move to
  new.drupal.org, with no date.

## Consequences

- A release reaches Packagist with no hand step, and carries notes.
- The session lasts about four weeks. `release-node.yml` opens the form each
  day and goes red when the session has ended. `CONTRIBUTING.md` holds the
  renewal steps.
- A failed post leaves the hand path. The `notes` job's summary holds the
  form's link and the notes.
- A commit that names no issue is absent from the notes, and the release has
  no short description.
- A release cannot be deleted. The post follows a green candidate run and a
  green tag run, and is sent once.
- When release creation moves to new.drupal.org, the host, the cookie name and
  the form change together. The script then stops on the first request.

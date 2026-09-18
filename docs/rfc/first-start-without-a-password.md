# First start without a typed password

Proposed on 2026-09-18. Implemented.

## Question

A first start asks for an administrator name and password at the terminal, and
refuses to run without them when there is no terminal. Someone trying Drupack
has to invent a password before they see a site. How does a first start reach a
logged-in Drupal without anyone typing one?

## What happens today

`askCredentials()` prompts for a name, defaulting to `admin`, then for a password
twice, hidden. With no terminal on standard input and no options,
`requireInitializationOptions()` throws `Missing Drupal administrator
credentials`. So a container, a CI job and a systemd unit all fail to start.

Both first-start paths consume the same two values. The SQLite default copies the
Seed site and runs `configureSeedAdministrator()`, which reads
`DRUPACK_ADMIN_USER` and `DRUPACK_ADMIN_PASSWORD`. Every other path runs
`site:install` with `--account-name` and `--account-pass`.

## Decision

A first start creates `admin` with a password from `random_bytes`, and nobody
ever sees it. Its only job is to keep the account from being passwordless.

The start then asks Drupal for a one-time login link, prints it, and opens the
browser on it. Drupal lands the reader on the account form, where they set their
own password.

The prompt goes. So does the refusal to start without credentials, and the two
tests that assert it.

## What the reader sees

- The readiness line, as today.
- The one-time login link, printed whether or not a terminal is attached.
- The browser opens on that link, under the rules that already decide whether a
  browser opens at all.

`--no-browser` still prints the link. A later start prints no link, because the
reader holds a session and a password by then. `drupack dr user:login` issues a
fresh link at any time.

## What stays

- `--admin-user`, `--admin-password`, `DRUPACK_ADMIN_USER` and
  `DRUPACK_ADMIN_PASSWORD` keep working. A caller who passes them gets the name
  and password they asked for, and the link still prints. Removing them would
  break scripted installs the README documents.
- `adoptSite()`'s seed-password check needs no change. It asks whether the
  published seed password is still in place, to catch a first start interrupted
  before its administrator step. A random password replaces that string exactly
  as a typed one did.

## Where the link is exposed

The link is a working credential until it is used or it expires. It reaches:

- standard output, so `docker logs`, a CI transcript and a journal keep it
- the argument list of the browser command, so `ps` shows it to other local users

The owner accepted both. A single-use link on a localhost address, for a site
whose reader is about to set a password, is worth the reach it gets.

## Acceptance checks

- A first start with no options and no terminal installs a site and exits zero.
- That start prints a one-time login link.
- Opening the printed link reaches Drupal's account form, logged in as `admin`.
- No password appears on standard output, in Site data, or in the Caddy log.
- `--admin-user` and `--admin-password` still set the name and the password.
- `--no-browser` prints the link and opens nothing.
- A later start prints no link and serves.
- `drupack dr user:login` issues a working link.
- A first start interrupted before its administrator step is still refused
  adoption, as `adoptSite()` refuses it today.

## Open questions

- Whether `CONTEXT.md` needs a term for the administrator account, which it has
  never defined.
- Whether the link should carry a shorter expiry than Drupal's default, given it
  is printed rather than mailed.

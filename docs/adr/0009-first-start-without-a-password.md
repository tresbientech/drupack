# A first start generates the password and prints a one-time login link

Accepted on 2026-09-18. Extended to every start on 2026-09-20. Limited on
2026-11-12 to a start a person watches.

## Context

A first start prompted for an administrator name and password, twice, hidden.
With no terminal it refused to start, so a container, a CI job and a systemd
unit all failed.

## Decision

- A first start creates `admin` with a password from `random_bytes`, which
  nobody sees. Its only job is to keep the account from being passwordless.
- A start a person watches asks Drupal for a one-time login link landing on
  `/admin/dashboard`, prints it, and opens a browser on it. A person watches
  when the start has a terminal, a file manager's console, or a detaching
  parent with a terminal.
- Any other start mints no link. It prints the command that mints one. The
  link is a working credential for uid 1, and a start under systemd or in a
  container writes its output to a log.
- `--admin-user` and `--admin-password` still set the account explicitly.
- A start whose mint fails prints the reason and the command that mints one by
  hand, then serves anyway, unless it generated the password and has no other
  way in.

## Consequences

The reader lands signed in, on the dashboard, and sets their own password from
the account form. No prompt, no refusal without a terminal.

An operator of a start nobody watches runs the printed command on the host to
sign in. A first start nobody watches serves even when uid 1 cannot sign in,
since it asks for no link.

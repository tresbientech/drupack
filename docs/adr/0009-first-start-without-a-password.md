# A first start generates the password and prints a one-time login link

Accepted on 2026-09-18. Extended to every start on 2026-09-20.

## Context

A first start prompted for an administrator name and password, twice, hidden.
With no terminal it refused to start, so a container, a CI job and a systemd
unit all failed.

## Decision

- A first start creates `admin` with a password from `random_bytes`, which
  nobody sees. Its only job is to keep the account from being passwordless.
- Every start asks Drupal for a one-time login link landing on
  `/admin/dashboard`, prints it, and opens a browser on it when a person is
  watching.
- `--admin-user` and `--admin-password` still set the account explicitly.
- A start whose mint fails prints the reason and the command that mints one by
  hand, then serves anyway, unless it generated the password and has no other
  way in.

## Consequences

The reader lands signed in, on the dashboard, and sets their own password from
the account form. No prompt, no refusal without a terminal.

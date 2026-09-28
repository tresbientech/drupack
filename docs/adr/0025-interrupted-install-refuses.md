# An interrupted install is never adopted and never dropped

Accepted on 2026-09-28.

## Context

A first start on MySQL or PostgreSQL installs Drupal into a database it found
empty. A crash after `site:install` has written tables leaves a database that
may boot as a site, though a recipe may have stopped halfway. The next start
used to see a site that boots and adopt it. Adoption skips removing
`automatic_updates` and `package_manager`, and serves a site that may be
incomplete.

## Decision

- The install step writes `install-started` in Site data after it finds the
  database empty, before `site:install` writes to it.
- A resumed install step with that record never adopts. A database holding
  tables stops the start with a message naming the database and the Site data
  directory. An empty one installs.
- The resume refuses when `settings.php` is gone, since the command line could
  then name any database.
- Nothing drops a table. The record names an address, and a tunnel, an alias
  or a restored dump can put another database's tables behind it.
- A start with no progress deletes a leftover record.

## Considered options

- Drop the tables over PDO and install again, which `site:install` cannot do
  without the `psql` or `mysql` client. Fingerprints such as the server's UUID
  or Drupal's `install_time` narrow the risk, and none proves the tables are
  the install's own.
- Ask at the terminal before a drop. A script, a container or a test has no one
  to answer.

## Consequences

- After a crash mid-install, a person empties the database once.
- Site data interrupted under an earlier release holds `first-install` and no
  record, so it resumes as it did then. That record was written before any
  emptiness check. Its file stays in Site data.

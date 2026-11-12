# Folders another account can change

Accepted on 2026-11-09, narrowed on 2026-11-11. Amends [ADR 0050](0050-the-launcher-owns-a-start.md).

## Context

A start runs code and reads secrets from folders other accounts on the machine
may be able to change:

- The cache root holds the PHP and Caddy a start runs.
- Site data holds `settings.php`, the database and the site's code. Nothing
  checked who could write it. A Windows folder under `C:\` inherits Modify for
  Authenticated Users.
- The engine's `vendor/` search climbed to the drive root. An autoloader another
  account planted in `C:\vendor` ran on `drupack drush`.
- `server.log`, the Serving lease and the Mercure secrets followed a link.

## Decision

### One rule, on the folder alone

`fsguard.Exposure` applies the rule to a resolved path. An error counts as
exposed.

- Unix: the current user owns the folder, and no group or other write bit is
  set. A check with readers also refuses group and other read bits.
- Windows: the current user, SYSTEM or Administrators owns the folder. The check
  reads no ACL.

A default cache root candidate sits in a per-account folder the launcher names.
That folder passes the same rule, without readers. Another account that made it
first in a shared temporary directory would own it.

### Which folders refuse and which warn

| Folder | Check | Result |
|---|---|---|
| `DRUPACK_CACHE_DIR` | the rule, with readers | refused |
| Each default cache root candidate | the rule with readers, and the rule on its per-account folder | refused, with a notice; the next candidate is tried |
| Site data, a named `--files-dir` | the rule | a warning, and the command goes on |
| The first `vendor/autoload.php` above the working directory | owner of the file, `vendor/` and the project folder; no link at the file or `vendor/` | refused |

The Site data warning is the owner's ruling. A refusal would stop sites that
serve today from a shared folder.

### What a start creates

- A new cache root and new Site data get mode `0700` on Unix. On Windows they
  get a protected ACL granting the current user, SYSTEM and Administrators.
- The engine start sets the umask to `077` before PHP runs, as a site start does.
- `server.log`, the lease and the Mercure secrets refuse a link and any other
  file that is not regular.

## Considered options

- Refusing open Site data lost to the owner's ruling for a warning.
- Stopping the `vendor/` search at the home folder lost. A project outside the
  home folder, such as `/srv/site`, would lose `drush`.
- A rule on every parent up to the root, with a reader for Windows ACLs, shipped
  first and was removed on 2026-11-11 by the owner's ruling. Its removal took out
  155 lines, among them an ACL reader using `unsafe`. FrankenPHP, Caddy and mkcert read no
  parent, and Caddy and mkcert leave hostile local accounts out of scope.

## Consequences

- A reader with Site data in an open folder sees the warning on every command
  until they run the fix it names.
- An account that can write a parent of the cache root or of Site data can
  rename that folder and put its own in its place. No check reports it.
- On Windows, an existing `DRUPACK_CACHE_DIR` or Site data folder whose ACL lets
  other accounts write passes. A folder under `C:\` inherits such an ACL. A
  folder a start creates gets the protected ACL.
- Windows has no open flag that refuses a link. An `Lstat` refuses one, and only
  an account that can already write the folder can swap a link in after it.
- The Windows cases run on a tag run or a dispatched `windows-amd64` run.

## Amendment, 2026-11-10

The engine start keeps the caller's umask instead of `077`. A server account and a
deploy account share a checkout through a group, and under `077` Drupal's uploads
and exports reached the deploy account as `0600`. The engine's own state takes
explicit `0700` or `0600` modes. A site start keeps `077`.

On Unix the `vendor/` check also accepts a path one of the reader's groups owns.
Any member of that group can then plant an autoloader, as it can already write the
code the server runs from that tree. Windows keeps the owner rule.

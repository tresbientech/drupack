# Folders another account can change

Accepted on 2026-11-09. Amends [ADR 0050](0050-the-launcher-owns-a-start.md).

## Context

A start runs code and reads secrets from folders other accounts on the machine
may be able to change:

- The cache root holds the PHP and Caddy a start runs. Its check read the root
  alone. A parent another account could write let that account rename the root
  and put its own runtime in its place.
- Site data holds `settings.php`, the database and the site's code. Nothing
  checked who could write it. A Windows folder under `C:\` inherits Modify for
  Authenticated Users.
- The engine's `vendor/` search climbed to the drive root. An autoloader another
  account planted in `C:\vendor` ran on `drupack drush`.
- `server.log`, the Serving lease and the Mercure secrets followed a link.

## Decision

### Two rules

`runtime.Exposure` applies both rules to a resolved path. An error counts as
exposed.

The leaf rule covers the folder itself:

- Unix: the current user owns it, and no group or other write bit is set. A
  check with readers also refuses group and other read bits.
- Windows: the current user, SYSTEM or Administrators owns it. No ACL entry for
  another account grants a write right, inherit-only entries included.

The ancestor rule covers each parent up to the root:

- Unix: the current user or root owns it. No group or other write bit is set,
  unless the sticky bit is.
- Windows: one of the trusted accounts or TrustedInstaller owns it. No other
  account may delete a child of the parent or the child itself, or grant itself
  that right.
- A right to create folders passes. Every signed-in Windows account holds it on
  a drive root, and it moves no existing entry.

### Which folders refuse and which warn

| Folder | Check | Result |
|---|---|---|
| `DRUPACK_CACHE_DIR` | both rules, with readers | refused |
| Each default cache root candidate | both rules, with readers | refused, with a notice; the next candidate is tried |
| Site data, a named `--files-dir` | both rules | a warning, and the command goes on |
| The first `vendor/autoload.php` above the working directory | owner of the file, `vendor/` and the project folder; no link at the file or `vendor/` | refused |

The Site data warning is the owner's ruling. A refusal would stop sites that
serve today from a shared folder.

The `vendor/` check reads owners alone. The leaf rule would refuse a reader's own
Windows project under `C:\`, which every start serves.

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

## Consequences

- A reader with Site data in an open folder sees the warning on every command
  until they run the fix it names.
- `DRUPACK_CACHE_DIR` must name a folder under parents only the reader, root or
  the system can change.
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

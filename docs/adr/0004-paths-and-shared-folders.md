# Paths take one canonical form, and folders another account can change are refused or flagged

## Context

Windows produces backslashes in rendered URLs, drive letters in icon paths and cache keys that change with the path spelling. A start also runs code and reads secrets from folders that other accounts on the machine may be able to change.

## Decision

### Canonical paths

- The canonical form is absolute, with forward slashes, no trailing separator and no `.` or `..` segment.
- Every path Drupack computes, exports or prints takes that form.
- Go keeps the native form inside the launcher. `fsguard.Canonical` converts at the variables exported to PHP, Caddy and the terminal.
- PHP resolves paths through `Symfony\Component\Filesystem\Path`, reached from the application's autoloader.
- A minted cache segment matches `^[a-z][a-z0-9.-]{0,31}$`. `internal/payload` states the pattern once, and `cmd/pack` enforces it at build time.
- `WindowsPathServiceProvider` registers the Drupal service overrides that keep rendered addresses canonical. They run on every platform, so Linux exercises the shipped path.

### One rule for a folder

`fsguard.Exposure` applies the rule to a resolved path. An error counts as exposed.

- Unix: the current user owns the folder, and no group or other write bit is set. A check with readers also refuses group and other read bits.
- Windows: the current user, SYSTEM or Administrators owns the folder. The check reads no ACL.

### Which folders refuse and which warn

| Folder | Check | Result |
|---|---|---|
| `DRUPACK_CACHE_DIR` | the rule, with readers | refused |
| A default cache root candidate | the rule with readers, then the rule on its folder | refused with a notice; the next candidate is tried |
| Site data, a named `--files-dir` | the rule | a warning; the command goes on |
| The first `vendor/autoload.php` above the working directory | owner of the file, `vendor/` and the project; no link | refused |

- On Unix the `vendor/` check also accepts a path owned by one of the reader's groups. Windows keeps the owner rule.
- A default cache root candidate sits in a per-account folder the launcher names. That folder passes the rule without readers.
- Site data only warns, because refusing would stop sites that serve from a shared folder.

### What a start creates

- A new cache root and new Site data get mode `0700` on Unix. On Windows they get a protected ACL granting the current user, SYSTEM and Administrators.
- A site start sets the umask to `077`. The engine start keeps the caller's umask and gives its own state explicit `0700` or `0600` modes.
- `server.log`, the Serving lease and the Mercure secrets refuse a link and any file that is not regular.

## Reason

One canonical form removes the defect class. Without a stated form each Windows path defect is a separate fix, and with one a test catches the next.

The rule reads the folder alone. A check of every parent up to the root needs an ACL reader on Windows. FrankenPHP, Caddy and mkcert read no parent, and hostile local accounts are out of scope for Caddy and mkcert. An account that can write a parent can rename the folder and put its own in place, and no check reports it.

The `vendor/` search climbs to the drive root, so an autoloader another account planted in `C:\vendor` would run on `drupack drush`. The owner check stops it. Stopping the search at the home folder would break a project in `/srv/site`.

The engine keeps the caller's umask because a server account and a deploy account share a checkout through a group.

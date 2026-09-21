# Drupack extracts its application once per release

Proposed on 2026-09-17. Revised on 2026-09-21. Not implemented.

## Context

FrankenPHP 1.12.7 extracts the embedded application in its package `init`, before Drupack code runs. It extracts into `os.TempDir()/frankenphp_<checksum>`, unless `EmbeddedAppPath` is set at build time with `-ldflags`. On every process start, `init` reads the whole tar and runs `os.Stat` on each file. It writes only the files that are missing. On Linux, a clean exit removes that directory, so the next start writes every file again. A killed process leaves it behind.

`launch.php` sets `TMPDIR`, `TEMP` and `TMP` to `<data>/runtime` and restarts once. Each Site data directory gets its own copy of the application. `launch.php` then links `<data>/settings.php` and `<data>/files` into that copy.

Measured on Windows 11, Intel Core i9-14900KF, Defender real-time protection on:

| Step | Time |
|---|---|
| Extracting 24,514 files, 174 MB | 18.7 s |
| First start of a new site | 30 s |
| `frankenphp.exe version` | 0.6 s |
| Restart of an existing site | 3.7 s |

A site start runs `frankenphp.exe` three times. The first process runs with the system temporary directory, so every computer also keeps a copy there.

## What the current release costs

Measured on a reporting machine on 2026-09-21, Drupack 0.1.8. One extraction holds 26,417 files and 206 MB. A first start writes it twice.

| Location | Directories | Files | Size |
|---|---|---|---|
| `%TEMP%\frankenphp_*` | 8 | 214,000 | 1.57 GB |
| `<data>\runtime\frankenphp_*` | 3 | 71,000 | 505 MB |

Nothing removes either set. FrankenPHP does try, on a clean shutdown:

```go
// Remove the installed app
if EmbeddedAppPath != "" {
    _ = os.RemoveAll(EmbeddedAppPath)
}
```

The result is discarded. On Windows the removal fails against open handles, so each version's directory stays. The `%TEMP%` copies belong to the process that restarts, which never reaches `Shutdown()`.

That same line rules out pointing `EmbeddedAppPath` at a shared directory. The first clean stop would delete the application every site reads from.

## Decision: one extraction per release

- Drupack builds FrankenPHP without its `app.tar` embed. Its `init` returns at `len(embeddedApp) == 0`, extracts nothing, and leaves `EmbeddedAppPath` empty.
- `packaging/entrypoint.go` embeds `app.tar` and extracts it into `<user cache>/Drupack/app/<checksum>`.
- The extraction writes a completion marker last. A start that finds the marker skips extraction and reads no tar.
- The entrypoint changes the working directory to that directory and builds its own path to `launch.php`. It leaves `frankenphp.EmbeddedAppPath` empty, so no shutdown removes the shared application. Nothing in the Caddy module reads that variable.
- The extraction reports progress on standard error, as bytes written against the manifest total.

## Decision: what each site keeps

- Every Site data directory uses the shared, read-only application directory.
- The application ships a fixed `web/sites/default/settings.php`. It includes `settings.php` from `DRUPACK_RUNTIME_DATA_DIR`.
- `settings.php` points `file_public_path` at `<data>/files` and sets `file_public_base_url` to the root-relative `/sites/default/files`. Caddy maps that URL prefix to `<data>/files`. No link enters the application directory.
- `launch.php` no longer restarts, because the application path no longer depends on the temporary directory.

## Decision: what leaves the cache

- An application entry lives while it has been used within 30 days. The [launcher cache retention RFC](../rfc/launcher-cache-retention.md) sets that rule for the runtime cache.
- A first start on this version removes the previous layout: a directory named `frankenphp_` plus 64 hexadecimal characters, under `%TEMP%` or `<data>/runtime`, that holds a file this product ships. It reports the count and the bytes freed.
- `drupack clean` reports and removes cache entries on demand, for the runtime cache and the application cache.

## Why a root-relative file base URL

`PublicStream::baseUrl()` returns `file_public_base_url` verbatim, and `LocalStream::getLocalPath()` applies no web-root constraint, so an absolute `file_public_path` outside the application works. An absolute base URL would carry the port into cached render output, and a start on another `--listen` would then serve a stale host. A root-relative value carries neither.

## Considered options

- The Windows launcher sets `TMP` to `<data>\runtime` before starting FrankenPHP. This removes the restart and the `%TEMP%` extraction on Windows only. Each site still extracts 26,417 files, and every process still reads the tar. The extraction stays inside upstream code, where no progress can be reported. Rejected again on the second review.
- Faster extraction inside FrankenPHP, such as parallel writes. This needs an upstream change and keeps the per-site copy and the restart.
- Defender exclusions for the cache and Site data. Each user sets them, they turn off scanning there, and their gain is not measured.
- A per-site overlay directory holding only `sites/default`, layered over the shared application. Caddy has no layered root, and PHP still resolves one root.
- A `drupack clean` command alone, with no removal on upgrade. The space stays used until someone learns the command exists.

## Consequences

- A new site no longer extracts the application. Only the first start of each release extracts it.
- A process start no longer reads 206 MB of tar or checks 26,417 files. The restart process disappears on every platform.
- The first start on this version frees the directories the previous layout left, 2.07 GB on the reporting machine.
- Two sites of one release share application files, so nothing may write into the application directory.
- Drupal caches absolute paths. The path changes with each release, as the per-site `frankenphp_<checksum>` directory does today.
- `generateAbsoluteString()` returns the root-relative value for a public file, because the stream wrapper returns what `file_public_base_url` holds. Mail and any caller needing a full URL need a check during implementation.
- `tests/conformance/replacement_cases.py` asserts the extraction lands in `<data>/runtime/frankenphp_*` at lines 65 and 87. It follows the new location.

## Resolved on review

- Public file URLs work with `file_public_path` outside the web root, under a root-relative `file_public_base_url` and a Caddy route. `LocalStream::getLocalPath()` keeps its containment check against the configured directory.
- `php-cli` and `php-server` do read `EmbeddedAppPath` after `init`. Setting it is unsafe, so the entrypoint sets the working directory instead.
- Old extractions leave under the 30 day retention rule, and `drupack clean` removes them on demand.

## Open questions

- Whether 30 days is the right window, which nobody has measured against real use.
- Whether `drupack clean` belongs with `drupack update` from the dependency updates RFC.

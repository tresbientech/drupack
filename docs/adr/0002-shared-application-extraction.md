# Drupack extracts its application once per release

Proposed on 2026-09-17. Not implemented.

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

## Decision

- Drupack builds FrankenPHP without its `app.tar` embed, so FrankenPHP's `init` extracts nothing.
- `packaging/entrypoint.go` embeds `app.tar` and extracts it into `<user cache>/Drupack/app/<checksum>`.
- The extraction writes a completion marker last. A start that finds the marker skips extraction and reads no tar.
- The entrypoint sets `frankenphp.EmbeddedAppPath` to that directory before a command runs.
- Every Site data directory uses the shared, read-only application directory.
- The application ships a fixed `web/sites/default/settings.php`. It includes `settings.php` from `DRUPACK_RUNTIME_DATA_DIR`.
- Caddy serves `/sites/default/files/` from `<data>/files`, so no link enters the application directory.
- `launch.php` no longer restarts, because the application path no longer depends on the temporary directory.

## Considered options

- The Windows launcher sets `TMP` to `<data>\runtime` before starting FrankenPHP. This removes the restart and the `%TEMP%` extraction on Windows only. Each site still extracts 24,514 files, and every process still reads the tar.
- Faster extraction inside FrankenPHP, such as parallel writes. This needs an upstream change and keeps the per-site copy and the restart.
- Defender exclusions for the cache and Site data. Each user sets them, they turn off scanning there, and their gain is not measured.

## Consequences

- A new site no longer extracts the application. Only the first start of each release extracts it.
- A process start no longer reads 174 MB of tar or checks 24,514 files. The restart process disappears on every platform.
- Two sites of one release share application files, so nothing may write into the application directory.
- Drupal caches absolute paths. The path changes with each release, as the per-site `frankenphp_<checksum>` directory does today.
- Extractions of old releases stay in the user cache until something removes them.
- `tests/replacement.sh` deletes `<data>/runtime/frankenphp_*` and has to follow the new location.

## Open questions

- Whether Drupal's public file URLs work when `file_public_path` points outside the web root, and `file_public_base_url` or a Caddy route supplies the URL.
- Whether `php-cli` and `php-server` read `EmbeddedAppPath` when a command runs, after `init` has finished.
- Which command removes extractions of old releases, and when it runs.

# The harness holds the extension platform facts

Accepted on 2026-10-22. Amends [ADR 0016](0016-extension-allowlist.md).

## Context

`runtime/php-extensions.txt` lists every PHP extension a build compiles. Two
facts about it differ by platform:

- `password-argon2` is a build input and never loads. Windows PHP also has no
  `pcntl` or `posix`.
- `opcache` reports itself as `zend opcache`.

`build/windows/build.ps1` and `tests/conformance/harness.py` each held both
facts. `build.ps1` used them for a load check on the runtime it had just built.
ADR 0016 asks for that: a name a platform cannot provide fails that platform's
build, and the exception goes in that platform's script. The conformance suite
checks the same set on the Packaged site's executable, by exact equality. It
runs in the same release job, on every Windows leg.

## Decision

- `harness.py` holds the platform facts, in `_UNLOADABLE` and `_REPORTED_AS`.
  No other file repeats them.
- `build.ps1` writes a php.ini `extension=` line for each listed name with a
  DLL in the PHP zip, and runs no load check.
- On Windows, the release job's conformance run fails the build on a name the
  runtime does not load. The publish job needs that job, so no release ships
  without the name. This replaces ADR 0016's build-step failure for Windows.
- Each language keeps its own reader of the allowlist: `extensions-list.sh`,
  `check-extensions.py`, `harness.py` and `build.ps1`. Each strips comments in
  one to five lines.

## Consequences

- A platform-specific extension needs one edit to `harness.py`, beside the
  allowlist line.
- A hand-run `build.ps1` builds a runtime that lacks an extension without
  failing. The suite's `test_extensions` names the extension.
- A PHP release that ships a DLL for `opcache`, `pcntl`, `posix` or
  `password-argon2` gets a php.ini line for it. PHP 8.5.11's zip ships none.

## Considered options

- Tags in the allowlist file, such as `pcntl absent=windows`, read by new
  Python and PowerShell modules. It needed two new files, a Dockerfile COPY, an
  offline-container mount and a test that runs `pwsh`.
- A shared Python module, with a Linux test that reads `build.ps1` as text. The
  facts stayed written twice.
- One reader for every caller. The Linux builder containers have bash and no
  Python, and the Windows build host has no guaranteed Python.

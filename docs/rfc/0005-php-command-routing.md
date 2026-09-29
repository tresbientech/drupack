# RFC 0005: secure PHP command routing

Proposed on 2026-09-29. Not implemented.

## Goal

Make the Engine executable's `drupack php`, and the PHP commands Drush starts
through it, keep the supported PHP CLI behavior without trusting shared
temporary files. Remove the extra PHP startup for ordinary scripts. Keep
Windows cache-path selection, with native tests for its interaction with
command-line settings.

A Packaged site has no `php` command and puts no PHP alias on PATH, so this
RFC leaves it unchanged.

This proposal follows a read-only review of commit
`69ac4e17e78b616224be47ae2ef69c8c80117ba8`. The review covered the PHP wrappers
and Windows short-path selection. Those files are unchanged at `9728499`.

## Current routing

The launcher sends `drupack php` to `engine/php.php` through the Runtime's
`php-cli` command. `engine/serve.php` puts `engine/bin` first on PATH before
it runs a Project folder's Drush or Drupal core command.

- The Unix alias executes the Runtime with `php.php` and the original arguments.
- The Windows alias does the same through `php.cmd`.
- The frontend answers informational options and evaluates `-r` code itself.
- Script execution replaces the frontend on Unix and starts a child on Windows.
- `-d` settings for a script or `-r` code become an INI file whose directory is appended to `PHP_INI_SCAN_DIR`.
- `-d` settings for an informational option go through `ini_set()`.

The Windows launcher resolves a non-ASCII cache root through its short name,
then tries a temporary-directory fallback. Both candidates undergo ownership
and ACL checks. Exhausting the candidates stops startup.

## Confirmed defects

Each case below was reproduced against the current frontend source and an
available bundled Linux Runtime, with native PHP comparisons where relevant.
The bundled PHP version was 8.5.10; the native comparison used PHP 8.4.26.

| Priority | Defect | Evidence |
|---|---|---|
| P1 | Generated INI files are trusted in a predictable shared temporary path | A planted `auto_prepend_file` executed before the requested script |
| P2 | Invocation settings reach descendant processes | A plain PHP child inherited its parent's `-d memory_limit=318M`; native PHP retained the configured `512M` baseline |
| P2 | INI serialization changes supported values | Native PHP preserved `/tmp/a;b`; the wrapper read `/tmp/a` |
| P2 | Lint accepts code PHP cannot compile | Duplicate function declarations passed wrapper lint and failed native lint with status 255 |
| P2 | Frontend helpers occupy user global names | `-r 'function environment() {}'` failed with a redeclaration error |

The planted-file test used the current account. The review did not impersonate
another account.

The directory name is a hash of the settings text, so another account can
predict it for any known setting. The frontend reuses a directory it finds and
checks neither its owner nor its mode. `sys_get_temp_dir()` decides who can
plant there:

- Linux: `/tmp` unless `TMPDIR` names another directory. Every account can write there.
- macOS: the per-user directory `TMPDIR` names.
- Windows: the per-user `TEMP` directory.

Cross-account exposure applies to a Linux host whose accounts share `/tmp`.

## Measured startup cost

Twenty warm runs of an empty script produced these local medians:

| Invocation | Median |
|---|---:|
| Runtime `php-cli` directly | 41.8 ms |
| Runtime through `engine/php.php` | 70.3 ms |
| Unix shell alias through the frontend | 70.4 ms |

The frontend added about 28.5 ms, or 68%, in this measurement. Launcher
extraction was excluded. The figures hold for this Linux host and Runtime.

Windows retains waiting parent processes because its replacement helper uses
`proc_open()`. The review did not measure Windows memory or startup cost.
Cache-path resolution runs at launcher startup and adds no per-request work.

## Proposal: contain the shared-directory exposure first

Move generated INI storage beneath the launcher's validated private Runtime
cache. Use the existing `PHPRC` directory, which the launcher sets to that
Runtime's directory, rather than another system-temporary root.

- Require the private-cache contract before creating or reusing configuration.
- Preserve Unix owner-only access and Windows inherited private ACLs.
- Keep generated configuration outside the declared Runtime payload files.
- Retain configuration with its Runtime cache entry until that entry is removed.
- Stop reading the old `drupack-php-*` temporary directories immediately.
- Encode each value as PHP's CLI encodes `-d`, which closes the `;` defect in the same change.

The cache's warm check reads only the files a Runtime manifest declares, so a
generated file leaves its entry valid. Cleanup deletes an entry once no start
serves from it, and the entry's generated files go with it.

Do not traverse or delete legacy shared directories during migration. Their
ownership and contents are untrusted. A missing private-cache precondition
must fail with a diagnostic before PHP starts with generated configuration.

This containment change closes the local injection path independently of the
CLI integration work. Configuration inheritance, lint and helper names stay
open until the Runtime integration ships.

## Proposal: execute CLI behavior at the Runtime boundary

Move supported PHP CLI dispatch ahead of PHP initialization in the Runtime.
Both the launcher and PATH aliases will reach that dispatch. Keep one option
parser and one definition of the supported option set.

The execution contract is:

- Apply `-d` values as invocation-local startup configuration, with PHP's CLI encoding rules.
- Preserve the caller's original `PHP_INI_SCAN_DIR` for user code and descendants.
- Execute scripts with their original working directory, arguments and standard streams.
- Execute `-r` code without frontend functions or constants in the user namespace.
- Compile without executing user code for `-l`, and preserve failure status.
- Start PHP once for a script invocation that needs no configuration changes.

A caller's own `PHP_INI_SCAN_DIR` stays in force, and the launcher keeps
setting `PHPRC` to the Runtime directory. The change covers only the
configuration Drupack writes for `-d`. Server startup and Packaged site
lifecycle commands keep their current dispatch.

## Integration prerequisite

The review did not establish an embedded-PHP API that meets the execution
contract. Before replacing the frontend, prove the required startup and
compilation hooks against the project's pinned FrankenPHP and PHP sources.

The proof must run a script, invocation-local `-d`, isolated `-r`, and a
compilation-only lint failure. It must build on every supported platform, and
on both Linux libc builds a release publishes.

Prefer a bounded extension to the existing Runtime CLI integration. If the
required hooks need a separate PHP executable or substantial dependency
changes, revise this RFC before migration. Do not replace native compilation
with tokenization or silently drop supported options.

## Windows cache paths

Keep the existing short-path selection and private-root checks. The review
found no qualifying defect in that selection logic. It read the source and ran
no Windows build.

Native validation must cover:

- Non-ASCII account, cache and temporary paths with startup `-d` settings.
- Disabled short-name generation, with a usable ASCII fallback.
- Exhausted candidates, with an explicit refusal and no partially configured PHP process.
- Untrusted ownership, writable ACLs and NULL DACLs on either candidate.
- Batch arguments containing spaces, quotes, percent signs and shell metacharacters.
- Exit status, Ctrl+C behavior and parent-process lifetime through the alias.

Generated configuration must remain reachable through the validated ASCII
cache path. A private root alone does not establish that PHP can read its
configuration on a non-ASCII Windows account.

## Acceptance tests

Add regression cases at the execution boundary. Where behavior depends on PHP,
compare against native PHP of the bundled version, since the review compared
8.5.10 with 8.4.26.

| Case | Required result |
|---|---|
| Planted legacy INI directory and extra INI files | Neither file affects the invocation |
| Concurrent invocations with different settings | Each observes its own settings |
| Parent `-d` followed by a plain PHP child | Child receives the original configuration environment |
| Absolute setting values containing `;` or `&` | Values match native CLI behavior |
| Duplicate declarations and other compile errors | Lint fails without executing the file |
| User declarations matching former helper names | Code executes without collisions |
| Script arguments, working directory and exit status | Values survive launcher and alias dispatch |
| Unsupported option | A named refusal replaces silent acceptance |

Measure direct and routed empty-script starts in alternating order after
warmup. Record median startup time and peak memory on each platform. Confirm
that the routed script path performs one PHP initialization.

## Delivery and removal

1. Ship the private-cache containment and value encoding, with the planted-configuration and `;` regression tests.
2. Prove the Runtime integration contract and record platform results.
3. Route launcher and alias calls through the shared CLI implementation.
4. Remove `engine/php.php` and its obsolete parser, INI transport and helper tests.
5. Update packaging, caller tests and CLI documentation in the same migration.

Keep the behavioral regression cases after removal. They test public command
behavior and must reach the replacement implementation.

Update [ADR 0022](../adr/0022-engine-executable.md) when the execution design
is accepted. Any changed process variable must also update
`runtime/environment.txt` and its contract test.

## Alternatives

- Keep PHP emulation and patch each defect. Private storage addresses injection, but compilation and startup behavior still need Runtime support.
- Bundle a separate PHP CLI executable. This adds an artifact and requires extension and configuration parity across platform builds.
- Use PHP installed on the host. This breaks the bundled-Runtime guarantee and operation on machines without PHP.
- Reject `-d`, `-r` or `-l`. This removes documented behavior and breaks callers that currently use those options.

The proposed Runtime integration keeps one bundled interpreter. Its platform
proof is a prerequisite to migration, while the security containment can ship
independently.

## Validation already completed

- Nine frontend unit cases passed on the bundled Linux Runtime.
- Eleven engine-helper cases passed with the launcher-provided environment.
- The Go Runtime tests passed on Linux.
- The Windows Runtime test executable cross-compiled successfully.
- Unix alias probes preserved quoted arguments and exit status.

Windows-native tests and full platform conformance were not run during the
review. The existing passing tests do not cover the reproduced defects.

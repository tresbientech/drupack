# RFC 0005: PHP command routing without emulation

Proposed on 2026-09-29. Design chosen on 2026-09-29. Not implemented.

## Goal

Make the Engine executable's `drupack php`, and the `php` its aliases give
Drush, run the pinned FrankenPHP's own `php-cli` directly. Delete the PHP
frontend that emulates PHP's command-line options, and every defect it
carries. A plain script starts PHP once.

A Packaged site has no `php` command and puts no PHP alias on PATH, so this
RFC leaves it unchanged.

## Current routing

- The launcher sends `drupack php` to `engine/php.php` through the Runtime's `php-cli`.
- `engine/serve.php` puts `engine/bin` first on PATH before it runs a Project folder's Drush or Drupal core command.
- `engine/bin/php` and `engine/bin/php.cmd` run the Runtime with `php.php` and the original arguments.
- `php.php` answers informational options and evaluates `-r` code itself.
- `-d` settings for a script or `-r` code become an INI file whose directory is appended to `PHP_INI_SCAN_DIR`.
- `-d` settings for an informational option go through `ini_set()`.

## Confirmed defects

A review of commit `69ac4e1` reproduced each case on the bundled Linux Runtime
(PHP 8.5.10) against native PHP 8.4.26. The files it read are unchanged at
`9728499`.

| Priority | Defect | Evidence |
|---|---|---|
| P1 | Generated INI files are trusted in a predictable shared temporary path | A planted `auto_prepend_file` executed before the requested script |
| P2 | Invocation settings reach descendant processes | A plain PHP child inherited its parent's `-d memory_limit=318M` |
| P2 | INI serialization changes supported values | Native PHP preserved `/tmp/a;b`; the wrapper read `/tmp/a` |
| P2 | Lint accepts code PHP cannot compile | Duplicate function declarations passed wrapper lint |
| P2 | Frontend helpers occupy user global names | `-r 'function environment() {}'` failed with a redeclaration error |

The directory name is a hash of the settings text, and the frontend reuses a
directory it finds without checking its owner. On Linux, `/tmp` is shared by
every account, so another account can plant settings. macOS and Windows use a
per-user temporary directory.

The frontend also adds about 28.5 ms to each script, 68% of an empty script's
start on the review's Linux host.

## Decision

Drupack stops emulating PHP's options. `drupack php` and the `php` alias accept
two forms, and pass each to the Runtime's `php-cli` unchanged:

- `SCRIPT [ARGUMENTS]`
- `-r CODE`

Any other leading option stops with a message that names it and lists the two
forms. That covers `-d`, `-l`, `-v`, `-m`, `-i`, `--ini`, `--ri`, `-f`, `-h`
and `-S`. `-r CODE` followed by arguments is refused too, since `php-cli`
passes it no `$argv`.

One Go check in the Runtime's entry point decides the form. The launcher and
both aliases reach it, so the rule lives in one place. A Packaged site's start
refuses the word.

`engine/php.php` and its tests leave the repository. Nothing writes an INI file,
so no temporary directory is read or created.

## Why drop the options

- Drush 13.8 never passes a PHP option. Its child processes run `php drush.php ARGUMENTS` through the script's shebang.
- FrankenPHP's own documentation handles `php` calls with a shim that strips `-d` and runs `frankenphp php-cli`. Projects that ship FrankenPHP copy that shim. None emulates `-d`, `-l` or `-r`.
- PHP 8.6 lets an embedder run PHP's real command line (php-src #21385). FrankenPHP's main branch calls it from `php-cli` on PHP 8.6 and later (FrankenPHP #1757, merged after v1.12.7).
- A Drupack release on that FrankenPHP and PHP 8.6 gets every option natively. The refusal then goes, with no emulation to remove first.

Refusing by name replaces the documented shim's silent strip. A caller that
passes `-d` learns its setting did not apply.

## Considered options

- Keep the frontend and move its INI files into the private Runtime cache. This closes P1 and leaves inheritance, lint and helper names open.
- Userland hooks: an `auto_prepend_file` stub applies `-d` with `ini_set()` and `opcache_compile_file()` lints. Startup-only settings such as `extension=` cannot apply, and lint needs `opcache.enable_cli` at startup.
- Runtime hooks: a cgo file in the Runtime wraps `php_embed_module.startup` to append `-d` to `ini_entries`, and hooks `zend_compile_file` for lint. This needs no FrankenPHP patch and rebuilds what FrankenPHP #1757 ships.
- A FrankenPHP patch adding `frankenphp_execute_cli` with an ini buffer and `php_lint_script`. Every build script applies it until upstream takes it.
- A real `php` binary from static-php-cli, built beside the embed library. It gives exact PHP behavior now, including `-S`, for an estimated 15 to 25 MB more per compressed download.

Each option keeps code that FrankenPHP on PHP 8.6 makes redundant.

## Acceptance tests

| Case | Required result |
|---|---|
| `drupack php SCRIPT a b` and `php SCRIPT a b` through each alias | Arguments, working directory and exit status match the script's |
| A plain script | One PHP startup: one Runtime process runs `php-cli` |
| `drupack php -r 'echo 1;'` | Prints `1` |
| `-d`, `-l`, `-v` and `-S` through the launcher and each alias | Stops before PHP starts, naming the option and the two forms |
| `-r CODE` followed by an argument | Stops, naming the extra argument |
| A planted `drupack-php-*` directory in the temporary directory | Nothing reads it |
| `drupack drush status` in a Project folder | Drush's child processes run on the bundled PHP |
| `./mercury-demo php x.php` | Refused as an unknown command |
| Windows `php.cmd` with spaces, quotes and `%` in arguments | Arguments reach the script unchanged |

## Delivery

1. Add the Runtime check and route the launcher and both aliases through it.
2. Delete `engine/php.php`, its tests and every reference to the emulated options.
3. Update the CLI reference, the README and ADR 0022 on the two forms and the refusal.
4. Record the refusal's removal as a backlog entry, to take once the pinned FrankenPHP runs PHP's command line.

The Windows cache-path selection stays as it is. Nothing new reads a path
through it.

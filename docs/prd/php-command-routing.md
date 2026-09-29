# PRD: PHP command routing without emulation

Source: `docs/rfc/0005-php-command-routing.md`, design chosen on 2026-09-29.

## Problem Statement

The Engine executable's `php` command runs a PHP frontend that emulates PHP's
command-line options. On a shared Linux host, another account can plant the
INI file that frontend reuses, and run code in my PHP. The emulation also
leaks `-d` settings into child processes, rewrites values holding `;`, passes
lint for code PHP cannot compile, and puts its own functions in my `-r` code.
Every script I run starts PHP twice.

## Solution

`drupack php` and the `php` Drush finds on PATH pass two forms straight to the
Runtime's `php-cli`: a script with its arguments, and `-r CODE`. Any other
option stops with a message naming it and the two forms. The frontend leaves
the repository, with its INI files and its defects. A script starts PHP once.

## User Stories

1. As a developer, I want `drupack php SCRIPT ARGS` to run my script with its arguments, so that I use PHP without installing it.
2. As a developer, I want the script to run in my current directory, so that relative paths resolve as they do with `php`.
3. As a developer, I want the script's exit status returned, so that my shell and CI see failures.
4. As a developer, I want `drupack php -r CODE` to run the code, so that a one-liner needs no file.
5. As a developer, I want `-d`, `-l`, `-v`, `-m`, `-i`, `--ini`, `--ri`, `-f`, `-h` and `-S` refused by name, so that I know my option did not apply.
6. As a developer, I want the refusal to list the two forms, so that I can rewrite my command.
7. As a developer, I want `-r CODE` followed by arguments refused, so that code expecting `$argv` never runs without it.
8. As a developer, I want a refused command to start no PHP, so that nothing of my script runs half-configured.
9. As a developer on a shared Linux host, I want no Drupack PHP command to read a temporary directory, so that another account cannot inject settings.
10. As a developer, I want a script to start PHP once, so that each command costs about 28 ms less.
11. As a Drush user, I want Drush's child processes to run on the bundled PHP through the `php` on PATH, so that `drupack drush` works with no host PHP.
12. As a Drush user on Windows, I want arguments with spaces, quotes and `%` to reach the script unchanged through `php.cmd`, so that Windows behaves like Linux.
13. As a caller that passes `-d` through the alias, I want the same refusal as `drupack php`, so that both routes agree.
14. As a Packaged site user, I want `./SITE php` refused as an unknown command, so that the site's command list stays as documented.
15. As a reader of the docs, I want the CLI reference and README to list the two forms and the refusal, so that no page promises `-d` or `-v`.
16. As a maintainer, I want the emulating frontend and its tests deleted, so that no code serves a mechanism that no longer runs.
17. As a maintainer, I want the rule that picks the form in one place, so that the launcher and both aliases cannot drift.
18. As a maintainer, I want a backlog entry to drop the refusal once the pinned FrankenPHP runs PHP's own command line, so that native options return without new emulation.

## Implementation Decisions

- The Runtime's entry point gains a `php` word. It checks the first argument and rewrites the call to `php-cli` unchanged, or stops with the refusal before PHP starts.
- The word runs only when the Runtime has no application directory, as for the Engine executable. A Packaged site's start keeps refusing it as an unknown command.
- The Engine launcher passes `php` and its arguments to the Runtime unchanged.
- The Unix alias runs the Runtime's `php` word with the original arguments. The Windows alias does the same.
- The emulating frontend and its unit file leave the repository. The runtime environment contract drops the frontend as a reader.
- ADR 0022 changes its `drupack php` passage to the two forms and the refusal.
- A backlog entry records when the refusal goes: once the pinned FrankenPHP calls PHP's own command line, from PHP 8.6.

## Testing Decisions

- A good test drives the executable, or an alias, the way a reader or Drush does, and checks the output, the exit status and the files touched.
- The Engine executable's conformance cases gain the cases below, beside the existing engine cases. No Go unit test covers the check.
- Cases:
  - `drupack php SCRIPT a b` through the launcher, and `php SCRIPT a b` through the alias: arguments, working directory and exit status.
  - `-r 'echo 1;'` prints `1`.
  - Each refused option through the launcher and the alias: exit 1, the option named, no PHP output.
  - `-r CODE` with an argument: refused, naming the argument.
  - A planted `drupack-php-*` directory in the temporary directory: never read.
  - A Packaged site's `php` word: unknown command.
  - A Windows `php.cmd` case with spaces, quotes and `%`, run by the Windows CI job.
- Prior art: the engine cases that run a Project folder's Drush, and the argument cases.

## Out of Scope

- Native PHP options through PHP 8.6 and a newer FrankenPHP.
- A separate PHP CLI binary.
- The Windows cache-path selection, which stays as it is.
- `php` for a Packaged site.

## Further Notes

- The measured frontend cost is about 28.5 ms per script on the review's Linux host.
- Drush 13.8 passes no PHP option to its child processes. A caller that does now gets a refusal where the frontend used to apply or ignore the setting.

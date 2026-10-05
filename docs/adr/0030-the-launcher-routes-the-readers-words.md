# The launcher routes the reader's words

Accepted on 2026-10-05. Amends [ADR 0014](0014-the-parser-owns-the-command-line.md).
Amended on 2026-10-05 by [ADR 0031](0031-the-engine-updates-itself.md): the
launcher handles `self-update` before routing, beside `detach` and `lay-app`.

## Context

The runtime's entry point routed every command line before FrankenPHP's
`main()`. It rewrote `os.Args` and set `DRUPACK_RUNTIME_DRUSH` and
`DRUPACK_RUNTIME_STOP`, which `launch.php` read to decide its mode again. The
builds copy that file into FrankenPHP's main package, so no Go test reached it.
The launcher held a second router for the Engine, and the entry point held a
second usage text.

## Decision

- The launcher's `runtimeArguments` routes the reader's words for both
  executables. A site's `php-cli`, the version flags, a Node word where the file
  carries Node, and the Engine's `php` pass unchanged. Every other line reaches
  `launch.php` or `serve.php` through `php-cli`, with its words.
- `launch.php`'s `command()` reads `start`, `drush` or `stop` from its argv, as
  `serve.php` does. It answers help, an unknown word and a Node word on a musl
  build.
- The entry point handles `php-server`, `browser-open`, the Engine's `php`, the
  version flags and Node. FrankenPHP's own command line takes `php-cli` and every
  other line.
- `launch.php`'s `HELP` is the one usage text. The command line is described in
  three places: `options()`, `HELP` and `docs/cli.md`.
- `build/qa.sh` asserts the `$argv` that `php-cli` hands a script.

## Considered options

- A pure routing function in a third file copied into FrankenPHP's package. It
  kept two routers and the two flags, and added a copy line to three build
  scripts.
- A shared routing package that both modules import. It coupled the launcher to
  an import path inside FrankenPHP's tree.
- Node in the launcher too. On Windows the launcher does not forward SIGTERM to
  its child, which the entry point's Node runner does.

## Consequences

- A routing change fails `go test` in the launcher, or `launch_test.php`.
- A direct call of the runtime with a reader word reaches FrankenPHP's own
  command line. The dev server's entry script names `launch.php` for that reason.
- The launcher, the entry point and `launch.php` each list the Node words.

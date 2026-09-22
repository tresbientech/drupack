# The parser owns the command line

Accepted on 2026-09-22.

## Context

`options()` in `runtime/launch.php` accepts 13 options. Three other surfaces
described the set, and each described a different one.

- `launch.php`'s own `--help` named 6 of the 13, under `drupack php-cli
  launch.php`, an invocation no reader types.
- `packaging/entrypoint.go`'s usage named all 13, reachable only as exactly
  `drupack --help`.
- `README.md` named four under "Useful options", and never named `--host`.

Nothing held them together, and no surface stated which options a first start
consumes or which ones `dr` ignores.

## Decision

- `docs/cli.md` is the CLI reference. It carries every option, its default, its
  environment variable, when it applies, and what rejects a bad value.
- The parser is the contract. `docs/cli.md`, `entrypoint.go`'s usage and
  `launch.php`'s help are asserted against `array_keys(options([], false))` in
  `tests/unit/launch_test.php`.
- `launch.php`'s help is rewritten to the `drupack [OPTIONS]` form and names
  all 13, so `--help` answers wherever the flag sits.
- `README.md` keeps its short list and links to the reference.

## Considered options

- Generate the texts from the parser. One source, no drift by construction. The
  generator needs a build step for the Go string, and a terminal usage wants
  grouping and examples that a generator has to be taught.
- Make `entrypoint.go`'s usage the single source and generate the page from it.
  A full reference then has to fit a terminal, or the terminal text grows past
  what a reader scans.
- Keep the surfaces hand-maintained. Free, and it produced the drift above.

## Consequences

- Two full usage texts exist, one in Go and one in PHP. The test keeps them
  equal, and neither may gain an option alone.
- A PHP unit test reads `packaging/entrypoint.go` as text. It scans the region
  between `Options:` and `Commands:`, so examples and `--dry-run` stay out of
  the comparison.
- Adding an option means touching four places. A missed one fails the unit run
  and the test names it.
- The test compares names. Defaults and environment variables stay prose, and
  can still drift.

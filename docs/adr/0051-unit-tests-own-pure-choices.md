# Unit tests own pure choices, the suite owns live behaviour

Accepted on 2026-11-08. Amends [ADR 0006](0006-one-conformance-suite.md).

## Context

ADR 0006 made the Python suite define every product behaviour, and tested nothing in the source tree. [ADR 0050](0050-the-launcher-owns-a-start.md) then moved the parser, the start plan, the TLS choice, the state store and the Caddyfile writer into Go functions with their own `go test` cases. From then on, both layers asserted the same refusals and choices.

On 2026-11-08 each method of the suite was matched against the Go and PHP unit tests. Of about 3000 method lines:

- 268 asserted what a unit test already asserted.
- 102 asserted the result of a pure function that no unit test covered yet.
- 2629 asserted what only a live run shows: an HTTP answer, a served certificate, a process, a database or an OS.

## Decision

- A choice that a pure Go or PHP function makes is tested by that function's unit test alone. Such choices include a refusal message, a parsed option, a start plan, a TLS source and an environment entry.
- A conformance case asserts what only the built executable on a real OS shows. Every case still drives the built executable, as ADR 0006 says.
- A case that asserts both keeps its live part. Its pure part goes to the unit test.
- A test of a build script that needs no built executable runs as a `test_*.py` unittest file, through `build/unit-tests.sh`.

## Consequences

- The suite lost 44 cases, and three more moved to `test_release_files.py`. Ten new Go tests and four tightened ones assert what they covered. The tests shrank by 234 lines in all.
- A changed refusal fails `go test` in seconds. The suite needs a built executable and about seven minutes on Linux.
- A pure function that a start never calls still passes its unit test. The live cases cover each command's main path, not each refusal.
- `phpArguments` in `runtime/entrypoint.go` builds only inside FrankenPHP's main package, in the builder image. Its two refusal cases stay in the suite.
- The mysql and pgsql refusals in `argument_cases.py` stay live. They are the one proof that PHP's `check` writes no Site data before a start refuses.

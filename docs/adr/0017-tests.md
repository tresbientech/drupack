# One conformance suite owns live behavior, and unit tests own pure choices

## Context

Behavior must be proven on every platform Drupack ships. A Windows defect passes every check that runs on Linux alone. The parser, the start plan, the TLS choice, the state store and the Caddyfile writer are Go functions with their own tests. A second layer can assert the same refusals twice.

## Decision

### The conformance suite

- One Python suite in `tests/conformance` defines every behavior of the built executable. It runs on Linux, macOS and Windows. A case class declares its platforms through `PLATFORMS`.
- Every case drives the built executable.
- A conformance case asserts what only the executable on a real OS shows. Examples are an HTTP answer, a served certificate, a process, a database or an OS call.
- A suite lock lets one run per machine use the ports at a time.
- One launcher cache per run, in a temporary directory. A run never touches the reader's own cache.
- One port per case, picked below every platform's ephemeral range. The picker checks the port and its successor, because a static listener binds both.
- Every wait in the wait table exceeds the product deadline below it.
### Test shares

- `DRUPACK_SHARD=K/N` runs the Kth of N shares of the test classes. `class-seconds.json` holds each class's measured seconds per platform. The classes go longest first to the share with the fewest seconds. A class missing from the table counts as the mean. `+extra` starts the last share with the seconds of the other work its job runs.

### Unit tests

- A choice that a pure Go or PHP function makes is tested by that function's unit test alone. Such choices include a refusal message, a parsed option, a start plan, a TLS source and an environment entry.
- A case that asserts both keeps its live part. Its pure part goes to the unit test.
- A test of a build script that needs no built executable runs as a `test_*.py` unittest file, through `build/test/unit-tests.sh`.
- Go tests run under the race detector with `build/test/go-tests.sh`, which also lints for each system a release targets.
- Four unit tests assert what an OS does and stay in their unit layer. They cover Ctrl+Break on a Windows process group, a lock that survives exec, PHP's argv, and the stop channel refusing a non-loopback dial.
- The mysql and pgsql refusals in `argument_cases.py` stay live. They prove that PHP's `check` writes no Site data before a start refuses.
- `phpArguments` and `iniLine` in `runtime/frankenphp/entrypoint.go` build only inside FrankenPHP's main package, in the builder image. Their refusal and `-d` cases stay in the suite.

## Reason

One suite per behavior, run where the behavior exists, proves a change on the platform that has it. Separate suites per platform let a defect on one platform pass every check on the others.

A refusal that a unit test asserts and a case asserts again costs suite time and adds no proof. A changed refusal fails `go test` in seconds, while the suite needs a built executable and many minutes. Keeping each fact in one layer shortens both and leaves a failure with one place to read.

A pure function that a start never calls still passes its unit test, so the live cases cover each command's main path, not each refusal. The suite splits by measured class time, because a split by class name leaves some shares far longer than others.

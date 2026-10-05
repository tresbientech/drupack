# PRD: One serving-state type for sites and folders

Source: backlog entry of 2026-10-05, settled on 2026-10-05 after four competing
designs.

## Problem Statement

A packaged site and an Engine folder each hold a Serving lease, a stop record
and a server log. The two keep them under different roots, through different
code.

- `SiteData` names the three files for a site. `engine/serve.php` restates them
  for a folder as `entryLease`, `entryStopRecord` and `entryLog`.
- The protocol is five free functions in `process.php`: `takeLease`,
  `leaseFree`, `detachServer`, `detachStart` and `stopServer`. Callers thread the
  lease handle between them by hand.
- No PHP test names any of those functions. Only the conformance suite reaches
  them, against a built executable.
- Go writes `stop.json`, and PHP reads its `token` and `port`. Nothing checks
  that the two agree.

## Solution

One `Serving` class in `process.php` owns the three files and the protocol.
Both executables already load that file. A site and a folder each build one,
and the restated getters and free functions go. A unit test drives the class
on a temporary directory with a real lock and a loopback stop channel. One
fixture holds the shape of `stop.json`, and a Go and a PHP test each check it.

## User Stories

1. As a maintainer, I want the lease, detach and stop protocol under a PHP unit test, so that a change to it fails before a build.
2. As a maintainer, I want one place that names `serving.lock` and `stop.json`, so that a site and a folder cannot drift apart.
3. As a maintainer, I want the lease handle held by an object, so that no caller threads it by hand.
4. As a maintainer, I want a renamed `stop.json` key to fail a test in Go or PHP, so that a stop never reads a record the server did not write.
5. As a reader, I want `start` and `stop` messages, exit statuses and file locations unchanged, so that my scripts and my running sites keep working.
6. As a reader, I want a second start refused with the same sentence as today, so that a served site still answers with its address.
7. As a reader, I want `stop` on a site that is not running to print `is not running.` and exit 0, as it does today.

## Implementation Decisions

The class:

- D1. `final class Serving` lives in `application/process.php`, which
  `engine/process.php` links to. `engine/serve.php` loads only that file, so a
  class elsewhere would need an autoloader the Engine does not have.
- D2. `new Serving(string $state, string $logDirectory)`. The lease is
  `$state/serving.lock`, the record `$state/stop.json` and the log
  `$logDirectory/server.log`. These three literals live in the class alone.
- D3. `public readonly string $stopRecord` holds the record's path, which both
  callers export to Caddy as `DRUPACK_RUNTIME_STOP_RECORD`.
- D4. `claim(): bool` takes the lease and keeps the handle in a private
  property. False means another start holds it. The object lives in the
  caller's scope until `pcntl_exec`, which keeps the handle open in the server.
- D5. `detach(bool $foreground, string $stopCommand, array $arguments): void`
  removes a stale record. It then detaches through the launcher's `detach`
  word, unless the start is in the foreground or owns its console. It
  replaces `detachStart` and `detachServer`.
- D6. `stop(string $what, string $named): int` replaces `stopServer` with the
  same order, messages, exit statuses and 15 second wait.

Callers:

- D7. `launch.php` builds `new Serving($site->directory, $site->logs())`. Its
  refusals and port check stay between `claim()` and `detach()`, as today.
- D8. `stopSite()` and `stopFolder()` each end in one `stop()` call.
- D9. `serve.php` builds `new Serving($entry, $entry)` for a folder.
- D10. These go: `SiteData::lease()`, `stopRecord()` and `serverLog()`;
  `entryLease`, `entryStopRecord` and `entryLog`; and the free functions
  `takeLease`, `leaseFree`, `detachServer`, `detachStart` and `stopServer`.

Contract and tests:

- D11. `runtime/watch/testdata/stop.json` holds one record. A Go test asserts
  that the record `serveStop` writes carries exactly the fixture's keys.
- D12. `application/tests/serving_test.php` runs through `cases.php` and
  `build/qa.sh`. Its stub server writes the fixture with its own port, and
  answers 204 only to the fixture's token.

## Testing Decisions

A good test builds the files and the stop channel a real start would leave,
calls the class, and reads its return, its output and the files.

`serving_test.php` covers:

- `stop` with no lease file prints `is not running.` and returns 0
- `stop` with a free lease and a stale record prints the same
- `stop` with a held lease and no record throws `still preparing`
- `stop` against the stub returns 0 and prints `stopped.`
- `stop` against a stub that answers 403 throws `refused the request`
- a second `claim` on a held lease returns false
- `detach` in the foreground removes a stale record and returns

The stub runs as a child process that claims the lease, so the lock and the
socket are real. The stop channel is a real boundary, so nothing is mocked.
`site_data_test.php` and `serve_test.php` are the prior art.

No existing test is replaced, since none names the deleted functions. The
conformance cases in `stop_cases.py`, `detached_cases.py` and `engine_cases.py`
keep covering start and stop end to end.

## Considered options

- A twelve-method class with `holder`, `channel`, `clearRecord` and `release`
  for a later `status`, `logs` or `restart`. Four of its verbs have no caller
  today.
- `start()` with an `$admit` callback for the site's port check, and `stop()`.
  The callback works around the site's three-way refusal instead of leaving it
  with its caller.
- A Go `stop` word in the launcher beside the record's writer, with PHP keeping
  the lease. Windows would need Go's `LockFileEx` to agree with PHP's `flock`,
  which only a tag build can test.

## Out of Scope

- `status`, `logs` and `restart`, which the backlog keeps.
- The Windows stop record's access, which the backlog keeps.
- The Go side of the stop channel.

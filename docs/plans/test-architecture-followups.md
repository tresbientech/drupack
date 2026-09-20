# Follow-ups from the conformance suite

> Source plan: [docs/plans/test-architecture.md](test-architecture.md)

Every item below was found by a review or an audit during the ten phases, judged
not to block the merge, and left for later. None changes what the suite proves
today. Merged on 2026-09-20 as `46c36c7`.

## Worth doing

- An unmarked case class skips on every platform in silence. `PLATFORMS` defaults
  to empty, and a class that declares none, or misspells a constant, runs nowhere
  while the suite stays green. A class that forgets to call the base
  `setUpClass` skips its platform and tool gate entirely. Make either shape
  impossible.
- `Site.start` opens its log with `wb`, so a second start on one `Site` throws
  away the first start's output. The site cases restart one `Site` on purpose, so
  a failure there loses the installing start's log.
- The network cases' probes run as Python source inside a container and carry
  their own timeouts, outside the wait table. They never import the harness, so
  the table cannot reach them as things stand.
- Nothing keeps the four platform lists in the release workflow's job conditions
  consistent. The `application` job must stay the union of the Windows and macOS
  lists, and only a reader enforces that today.
- Comments across the suite cite `tests/windows/*.Tests.ps1` by name as the
  provenance of a case. Those files no longer exist.

## Small

- The default-port case names 7225 when it fails, without separating a bind
  conflict from any other early exit.
- The `application` job prints the free space before its build and not after,
  where the Linux job prints both.
- `HTTPError` sits in a retry tuple beside `URLError`, which already covers it.
- The race case's log assertions carry no `inspect <path>` pointer, where the
  rest of that module's failures do.
- A container log lands in its class's results directory rather than its case's.
  One method per class makes the two equivalent today.
- The concurrent cold-start case can leave the second process running when the
  first exceeds its budget, as the shell file it came from also did.
- A refusing start's timeout kills the direct child rather than a process group.
  On POSIX the product replaces its own process, so nothing survives today.
- The Windows stop branch can spend its budget twice in the worst case, once on
  `taskkill` and once waiting for the exit.

## Accepted narrowings

These lost nothing a case asserted, and a later reader should not rediscover
them as regressions.

- The same-release concurrent launcher case runs the real executable with
  `--help`, so it cannot assert that each process forwarded its own arguments.
  The single-process case and the different-release case cover that.
- The network cases each install a fresh site, where the shell file ran two
  modes against one installed directory. No assertion depended on the sharing.

# One conformance suite covers every platform

Accepted on 2026-09-19.

## Context

Nine suites in bash and PowerShell asserted overlapping behaviour, and none ran
on more than one platform. A Windows defect could pass every check.

## Decision

- One Python suite defines every product behaviour and runs on Linux, macOS and
  Windows. A case class declares the platforms it runs on through `PLATFORMS`.
- Every case drives the built executable. Nothing tests the source tree.
- One launcher cache per run, in a temporary directory. A run never touches the
  reader's own cache.
- One port per case, taken from the operating system. One case owns the default
  port 7225 and runs alone.
- Every wait in the table exceeds the product deadline below it.

## Consequences

A behaviour change is proven on the platform that has it, in the suite that
ships. The run takes about seven minutes on Linux and longer on Windows.

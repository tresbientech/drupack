# Launcher cache retention

Proposed on 2026-09-18. Not implemented.

## Question

The launcher unpacks its runtime into one directory per version and removes
every other one on a successful start. Drupack's model gives each site its own
folder, and the [dependency updates RFC](dependency-updates-and-compatibility.md)
pins Site data to the version that wrote it. Two sites on two versions therefore
alternate, and each start deletes the other's directory. How long does a cache
entry live?

## What ships today

`packaging/launcher/internal/runtime/cache.go` names each entry
`Key(version, payload)`, the version string joined to the payload digest. Two
builds that share the version string `dev` get separate entries.

`removeOthers` then deletes every entry except the active one. It keeps `active`,
`lock` and any staging directory another process owns.

One entry holds 417,724,848 bytes on Linux at 0.1.1. A cold unpack costs about
0.67 s plus the disk write.

## The case that breaks

A site owner runs one site on 0.1.1 and a second on 0.1.2, each in its own
folder. Starting either one deletes the other's entry. Every start becomes a cold
start, so each pays the unpack and writes 418 MB.

Nothing loses data and no running server stops. `DRUPACK_RUNTIME_BINARY` is
re-executed only during startup, in the install steps, so a serving site never
reaches back into its directory.

## Decision

A cache entry lives while it has been used within 30 days.

- A start writes the current time to a `used` file inside its entry, under the
  cache lock it already takes.
- Cleanup removes entries whose `used` file is older than 30 days, or missing.
- `active`, `lock` and another process's staging directory stay, as they do now.

Two sites on two versions both stay warm. A version that stops being used leaves
a month later, so disk use self-limits without a command to run.

## Why a written file

A read does not reliably update a directory's access time. `relatime` is the
Linux default and updates `atime` at most once a day, `noatime` never does, and
the macOS and Windows behaviour differs again. An explicit write under the lock
gives one rule on every platform.

## What this does not change

- The cache key, which already separates versions and builds.
- The fallback to a previously installed version when staging fails.
- `DRUPACK_CACHE_DIR` and the fallback to the temporary directory.
- The count of entries a single-version machine holds, which stays at one.

## Acceptance checks

- Starting version A, then B, then A again unpacks twice, not three times.
- An entry whose `used` file is 31 days old is removed on the next start.
- An entry whose `used` file is 29 days old survives.
- An entry with no `used` file is removed, which covers entries written by 0.1.1.
- A start updates its own entry's `used` file.
- Two simultaneous starts of one version still produce one entry.
- A machine that only ever runs one version holds one entry.

## Open questions

- Whether 30 days is the right window, which nobody has measured against real
  use.
- Whether `drupack` gains a command to report cache size and clear it, and
  whether that belongs with `drupack update` from the dependency updates RFC.
- Whether an entry in use by a running process needs protection beyond this,
  which matters only if a future change re-executes the runtime after startup.

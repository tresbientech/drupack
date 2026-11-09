# Pack takes a target and a payload directory

Accepted on 2026-11-09.

## Context

`launcher/cmd/pack` took 13 flags, and twelve lines called it. Each caller
derived the entry name, the architecture, the musl mark and four payload paths
from a target and a directory it already held. Bash, PowerShell and the
workflow each rebuilt the Node archive's name, which `node.Archive` defines in
Go.

## Decision

- Pack takes `-target`, `-runtime`, `-payload`, `-version` and `-output`, plus
  `-site-version` and `-engine`. The launcher source is the working directory.
- `-target` has the form `runtime.Target` returns. The architecture, the libc
  and the entry name follow from it. A name outside `targets.json` stops pack.
- A payload directory has one layout: `build.PayloadName`,
  `build.ChecksumName`, the site's `siteconfig.OutputName` and a `node/`
  directory of archives named by `node.Archive`. An engine payload holds the
  first two.
- A site whose `site.json` names `node` carries the archive of its target. A
  missing archive stops pack. A target Node does not build for carries none.
- Pack prints one line per resolved input before it builds.
- `drupack-build` lays that directory in its work directory and passes it.

## Consequences

- A new target or a renamed payload file changes the Go definitions alone.
- The macOS and Windows build scripts packed without Node when the archive was
  absent. Pack now stops when the site names `node` and the archive is absent.
- A wrongly packed file is diagnosed from the build log, which names each
  input.

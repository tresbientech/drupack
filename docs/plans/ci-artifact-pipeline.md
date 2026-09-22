# Plan: the release graph builds each thing once

Goal: a tag run builds the application once, compiles PHP only when its inputs
change, and states its target set in one place. Payoff: about 110 runner-minutes
instead of 191, a 30-minute wall clock instead of 45, and a graph whose edges
match what a job consumes.

## What was decided

`docs/adr/0018-release-graph.md` holds the decision and the rejected
alternatives.

## Preconditions

Both are met. `e0308aa` moved the build inputs into `application/`, `build/`,
`launcher/` and `runtime/`, and this plan names the paths it left. `fb2b410`
fixed the `spc spc-config` call in `runtime/embed.sh`, so a Linux runtime links
again.

---

## Phase 1: One gate job, one target set

### What to build

`changes` and `extensions` merge into `gate`, which checks out once and runs the
lock check as its second step.

`gate` writes the version and one JSON array per platform family: `linux`,
`macos`, `windows`. An array holds one object per architecture. A family absent
from the run gets `[]`.

Each platform job reads `strategy.matrix.include` from `fromJSON`, guards on
`needs.gate.outputs.<family> != '[]'`, and drops its `exclude` block and its
`contains(fromJSON(...))` filter.

### How to check

Dispatch with `platform: linux-amd64`, with `platform: all`, and push to a
branch. Compare the job set against today's run for the same trigger.

### Done when

- No `exclude` block remains in the workflow.
- A main push draws Linux amd64 alone, with no skipped nodes.
- A dispatch naming one platform draws that platform alone.

---

## Phase 2: Builder images in the registry

### What to build

`runtime/builder-tag.sh` prints the tag for a libc and architecture: a SHA-256
over the FrankenPHP commit, the PHP version, `runtime/php-extensions.txt`,
`runtime/php-extension-libs.txt`, the libc and the architecture, truncated to 16
characters. `runtime/build-builder.sh` reads the same values, so both take them
from one place.

`linux-runtime` pulls `ghcr.io/tresbientech/drupack-builder:<tag>` and passes it
as `MUSL_BUILDER` or `GNU_BUILDER`. A pull that fails builds the image, which
stays on the runner under the same name, and a push publishes it for later runs.
A fork skips the push and builds on every run.

A separate `prepare-builder` job was the first shape. It buys nothing here: the
image and the runtime stand in one-to-one, so a miss would push 8.6 GB and pull
it straight back.

### How to check

Run twice on an unchanged tree. The second run's `Pull or build the builder
image` step finishes in about three minutes. Change one line in
`runtime/php-extensions.txt` and confirm all four rebuild.

### Done when

- A warm `linux-runtime` job finishes in under 12 minutes.
- The workflow declares `packages: write`.
- A run on a fork passes with no registry write.

---

## Phase 3: One payload, and a packer on the runner

### What to build

`payload` runs whenever `gate` says to build, for every platform family.

The `linux` job downloads `payload-archive` beside the two runtime artifacts and
runs `go run ./cmd/pack` with the Go toolchain it installs for the unit tests.
Its `docker build` step goes, along with the two `--build-context` flags.

The `Dockerfile` keeps every stage. `CONTRIBUTING.md` documents `--target
artifact` as the local build, which packs through `packed` from the `app` stage
and both runtime stages.

### How to check

Compare the packed Linux executable against the one a previous run produced:
same size within the version string, same conformance result, and the Alpine
start still passes.

### Done when

- The application is built once per run.
- The `linux` job pulls no builder image.
- `docker` appears in the `linux` job only for the Alpine start.

---

## Phase 4: Cache keys, leftover steps, and the numbers

### What to build

The macOS cache key hashes `build/macos/build.sh`,
`runtime/php-extensions.txt` and `runtime/php-extension-libs.txt`.

The `Free disk space` step stays in `linux-runtime` and `payload`, which pull
large images. It goes from `linux`.

`docs/backlog.md` records the measured runner-minutes and wall clock for a tag
run, replacing the estimate in the ADR if they differ.

### How to check

Change an extension name and confirm the macOS job rebuilds its buildroot rather
than restoring a stale one.

### Done when

- A tag run reports its runner-minutes in the backlog entry.
- No job cleans disk it does not need.

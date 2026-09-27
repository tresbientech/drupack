# PRD: A site builds for macOS and Windows

Source: the design session of 2026-09-27. ADR 0025, written with the first phase, records the decision.

## Problem Statement

A site owner builds executables with Drupack's reusable GitHub workflow. That
workflow builds Linux targets only. The Mercury Demo that Drupack releases runs
on Linux, macOS and Windows. A site built on the same engine cannot reach a
reader on macOS or Windows.

Drupack's own release compiles each macOS and Windows runtime on a runner of
that system. The site workflow has no such runner and no access to those
runtimes. `drupack-build` refuses every target outside Linux.

## Solution

Each engine release publishes its macOS and Windows runtimes as Release files.
The site workflow builds the site payload once, on Linux. For each macOS or
Windows target in the Site contract, a job on that system does four things:

- downloads the matching runtime from the engine release
- checks its SHA-256 against the engine's `checksums.txt`
- packs it with the payload
- runs the conformance suite on the result

The site release then lists every target in the shape Drupack's own release
uses. A site that adds PHP extensions keeps building Linux only. GitLab, Gitea
and local builds keep building Linux only.

## User Stories

1. As a site owner, I want to list `macos-arm64`, `macos-amd64` and `windows-amd64` in `platforms`, so that my release reaches readers on those systems.
2. As a site owner, I want my GitHub workflow to build those targets with no change beyond `platforms` and the engine tag, so that adding a system costs one line.
3. As a site owner, I want every target packed from one payload, so that each system serves the same site.
4. As a site owner, I want a macOS or Windows target refused when my site adds PHP extensions, naming them, so that I never ship a runtime without them.
5. As a site owner, I want a workflow pinned to a branch or a commit to refuse macOS and Windows targets and ask for an engine tag, so that the runtime it packs is a published one.
6. As a site owner, I want the runtime's SHA-256 checked before packing, so that a corrupt or altered download stops the build.
7. As a site owner, I want each macOS and Windows executable to pass the conformance suite on its own system, so that a broken build never reaches my release.
8. As a site owner, I want a failed native job to upload its test results, so that I can read why it failed.
9. As a site owner, I want the `platforms` input to override the contract for native targets too, so that one run can build a subset.
10. As a site owner, I want the release to list each executable as `NAME-VERSION-TARGET`, with `install-NAME.sh` and `install-NAME.ps1`, so that readers install with one line on any system.
11. As a site owner, I want `checksums.txt`, `release.json`, the SBOM and the provenance to cover every target, so that readers can verify any download.
12. As a reader on macOS, I want the install script to pick my architecture's build, so that I download the one that runs.
13. As a reader on Windows, I want the PowerShell install script to install the site's Windows build, so that I start it the way Mercury Demo readers do.
14. As a site owner on GitLab or Gitea, I want a macOS or Windows target refused with a message naming the GitHub workflow, so that I know where those targets build.
15. As a site owner building on my own machine, I want the same refusal, so that the rule holds everywhere.
16. As an engine maintainer, I want the runtime files taken from the jobs that already compile these runtimes, so that no runtime compiles twice.
17. As an engine maintainer, I want the runtime files covered by the release's attestation, so that their origin is provable.
18. As an engine maintainer, I want the pack tool and the conformance suite taken at the engine commit the caller pinned, so that every piece of a build matches.
19. As an engine maintainer, I want Drupack's own call of the site workflow to stay Linux-only, so that a release run never waits on files it has not published.
20. As an engine maintainer, I want the example repository to build every target after each engine release, so that the native path runs against published files.
21. As an engine maintainer, I want the target split tested without a runner, so that its rules are proven in seconds.
22. As a reader of the site-building guide, I want the target list and the extension limit stated there, so that I learn them before my first build.

## Implementation Decisions

Site contract:

- `platforms` accepts `macos-amd64`, `macos-arm64` and `windows-amd64`, beside
  the two Linux targets.
- A contract with added extensions and a macOS or Windows target is refused.
  The message names the extensions and the targets.

Target split, in `drupack-build`:

- It resolves the targets from the contract and the `--platform` override. It
  builds the Linux ones and leaves the others.
- A flag the site workflow passes makes it accept macOS and Windows targets. It
  then exports the payload and writes the list of those targets beside it.
- Without that flag, a macOS or Windows target is refused, naming the GitHub
  workflow.

Runtime Release files:

- The engine's macOS and Windows jobs upload the runtime directory they compile.
- The release step archives each one as `runtime-VERSION-TARGET.tar.zst`.
  `checksums.txt`, `release.json` and the attestation cover it. No install
  script names it.

Native pack script:

- One bash script. It runs on the macOS runners, and through Git Bash on the
  Windows runner.
- Inputs: the release base URL, the target, the payload directory and the output.
- It fetches the runtime file and `checksums.txt`, and checks the SHA-256. It
  then unpacks the runtime and runs the pack tool, with the entry `drupack` on
  macOS and `frankenphp.exe` on Windows.
- A missing file or a mismatched SHA-256 stops the build and names the file.

Site workflow:

- The engine job's peeled commit and tag feed every later job.
- The build job, in the Linux container, builds the Linux targets and exports
  the payload and the native target list.
- A native job runs per target on the runner Drupack's release uses for it. It
  checks out the engine at the pinned commit and the site for its tests.
- The publish job waits on both. It writes the Release files over every
  executable, a step the build job holds today.

Engine release:

- Its own call of the site workflow passes the Linux targets only.

## Testing Decisions

A good test drives a module through its public interface and checks what a user
sees: files written, exit codes and messages. It never checks a helper.

- Contract and target split: Go unit cases, as in the existing contract and
  build tests. They cover accepted targets, the refusal with added extensions,
  the split with and without `--platform`, and the refusal naming GitHub.
- Runtime Release files: host cases, as the install cases do. A runtime
  directory becomes `runtime-VERSION-TARGET.tar.zst`, listed in `checksums.txt`
  and `release.json`.
- Native pack script: host cases that serve a written release from a local HTTP
  server, as the install cases do. One packs a site on Linux with a Linux
  runtime file. One alters a byte and expects a refusal naming the file.
- Native conformance: each native job runs the suite with the site's tests, as
  the engine's macOS and Windows jobs do.
- Workflow wiring: the example repository's release after each engine tag. Its
  asset list is the check.

## Out of Scope

- A per-site runtime with added extensions on macOS or Windows.
- macOS and Windows targets on GitLab, Gitea and local builds.
- A native test run of `linux-arm64`, which keeps packing in the amd64 image.
- Windows on arm64.
- Signing or notarizing macOS and Windows executables.
- macOS and Windows targets for an engine pinned to a branch or a commit.

## Further Notes

- The macOS and Windows runtimes do not depend on the payload. Drupack's release
  packs Mercury and the engine executable from one compiled runtime.
- The feature ships in an engine release after 0.5.4. The example repository
  then moves its pin and adds the three targets.

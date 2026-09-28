# PRD: Bundled Node

Source: design settled on 2026-09-29. Builds on the one runtime per Linux file
PRD.

## Problem Statement

A site owner has Drush commands that run Node programs. WordPal converts a
WordPress theme by running WordPress Playground through `npx`, and needs Node
24.15 or later. A Packaged site serves with nothing installed, yet such a
command fails on a host without Node, or with an older one.

The reader then has to install a Node release they never chose, which undoes
the single download Drupack offers.

## Solution

A site's `drupack.yml` asks for Node. The build fetches that release, verifies
it and packs it with each executable. The site's server and Drush commands find
it as `node`, `npm` and `npx`, and the reader runs those three as commands of
the executable.

`node: true` takes the newest LTS when the site is built. A major line or an
exact version picks another release.

## User Stories

1. As a site owner, I want `node: true` in `drupack.yml` to carry the newest Node LTS, so that I get a supported release without looking it up.
2. As a site owner, I want `node: 24` to carry the newest 24.x at build time, so that I stay on a major line and still get its fixes.
3. As a site owner, I want `node: 24.21.0` to carry exactly that release, so that a rebuild never changes it.
4. As a site owner, I want a malformed `node` value refused with the accepted forms, so that I fix it before a long build.
5. As a site owner, I want a site without `node` to carry nothing extra, so that sites without Node programs stay the same size.
6. As a site owner, I want the build to print the exact Node version it chose, so that I know what shipped.
7. As a site owner, I want the exact version recorded in `site.json`, so that a later reader of the build finds it.
8. As a site owner, I want a version with no build for one of my targets to stop the build and name that target, so that no platform ships without Node.
9. As a site owner, I want each Node download verified against the release's signed checksums, so that a tampered mirror never reaches my readers.
10. As a site owner, I want a signature by a key the repository does not hold to stop the build, so that an unknown signer is never trusted.
11. As a site owner, I want a download whose hash differs from the signed checksums to stop the build, so that corruption never ships.
12. As an end user, I want `./SITE node`, `./SITE npm` and `./SITE npx` to run the bundled release, so that I use Node with nothing installed.
13. As an end user, I want those commands to run in my current directory with my arguments, so that they behave like the tools they name.
14. As an end user, I want a Drush command the site runs to find the bundled `node` and `npx` first on PATH, so that it never picks an older host Node.
15. As an end user, I want the server's PHP processes to find the same Node, so that a request running a Node program gets the same release.
16. As an end user, I want Node unpacked once per version into the cache, so that later starts cost nothing.
17. As an end user, I want `clean` to remove unpacked Node releases with the rest of the cache, so that one command frees the space.
18. As an end user on a musl build, I want `node`, `npm` and `npx` to say that the glibc build carries Node, so that I know which file to use.
19. As an end user of a site without Node, I want `node` refused as an unknown command, so that the command list matches what the site carries.
20. As an end user on Windows and macOS, I want the same three commands, so that every platform behaves alike.
21. As an end user, I want npm's own downloads and cache to work as they do with a host Node, so that `npx` fetches packages as usual.
22. As a maintainer, I want the release resolution and verification tested without a network, so that its rules are proven in seconds.
23. As a maintainer, I want a fixture site with `node: true` in the conformance suite, so that the commands and PATH are proven without WordPal.

## Implementation Decisions

Contract:

- `node` accepts `true`, a major number, or an exact `MAJOR.MINOR.PATCH`
  version. Absent or `false` means no Node.
- The build resolves the value to one exact version and writes it to
  `site.json`.

Node resolver, one module the build calls:

- It takes the contract value, the site's targets, nodejs.org's release index,
  each release's `SHASUMS256.txt` with its signature, and the keyring kept in
  the repository.
- `true` picks the newest release the index marks LTS. A major picks that line's
  newest release. An exact version must appear in the index.
- It maps each target to a Node build: glibc Linux, macOS by architecture,
  Windows x64. A musl target carries no Node.
- It verifies the checksum file's OpenPGP signature against the kept keyring,
  then each archive's SHA-256 against that file. Any failure stops the build.
- It returns the exact version and one verified archive per target.

Packing and start:

- The build unpacks each verified archive and packs its files as a payload
  beside the runtime, keyed by Node version.
- The launcher unpacks Node into the cache on first use, once per version.
- The launcher puts Node's executable directory first on PATH for the server
  and for `drush`.
- `node`, `npm` and `npx` are commands of a site that carries Node. The command
  line parser owns them, as it owns `php`.
- Node's own license files ship with it.

## Testing Decisions

- A good test gives a module its inputs and checks its output or its refusal,
  never its internal steps.
- The Node resolver gets Go tests with a fixture index, fixture checksum files
  and a test keyring. They cover the three value forms, a missing target, a bad
  signature, an unknown key and a hash mismatch, all without a network.
- The contract parser gets cases for each accepted form and each refusal,
  beside the existing field tests.
- A conformance case packs a fixture site with `node: true`. It runs the three
  commands, checks the version they report, and checks a Drush command sees the
  bundled `node` first on PATH.
- The same case on a musl build checks the refusal that names the glibc build.
- Prior art: the contract parser tests, the build planner tests, and the
  launcher cases that pack fixture sites.

## Out of Scope

- Node for the `drupack` Engine executable, which has no Site contract.
- Node on musl, which has no official arm64 build.
- Shipping npm packages such as Playground inside the executable.
- A semver range in the `node` value.
- WordPal in Mercury Demo, which its own PRD covers.

## Further Notes

- Node 24.21.0 is the newest LTS on 2026-09-29. Node 26 becomes LTS in late
  October 2026, and `node: true` follows it on the next build.
- The keyring changes when Node adds a releaser. The build then stops on the
  unknown key until the repository's keyring is updated.

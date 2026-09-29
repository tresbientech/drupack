# A site carries Bundled Node

Accepted on 2026-09-29.

## Context

Some Drush commands run Node programs. WordPal converts a WordPress theme by
running WordPress Playground through `npx`, and needs Node 24.15 or later.
Such a command fails on a host without Node, or with an older one. A reader
of a Packaged site then installs a Node release they never chose.

## Decision

- `node` in `drupack.yml` takes `true`, a major such as `24`, or an exact
  `MAJOR.MINOR.PATCH`. Absent or `false` means no Node.
- `true` takes the newest LTS in nodejs.org's release index. A major takes
  that line's newest release. An exact version must appear in the index.
- The build prints the exact version and records it in `site.json` as `node`.
- The build checks the OpenPGP signature on the release's `SHASUMS256.txt`
  against a keyring kept in this repository. It then checks each archive's
  SHA-256 against that file. Any failure stops the build.
- The keyring is `launcher/internal/node/release-keys.asc`, an armored export
  of the `gpg-only-active-keys` keyring of
  [nodejs/release-keys](https://github.com/nodejs/release-keys).
- Each glibc Linux, macOS and Windows file carries the release beside its
  runtime. A musl file carries none, since Node publishes no official musl
  build for arm64.
- The launcher unpacks the release into the cache once per version. It puts
  the release's executable directory first on `PATH` for the server and Drush.
- `node`, `npm` and `npx` are commands of a site that carries Node.
- The engine executable carries no Node.

## Considered options

- Downloading Node on a reader's first start. An offline host would get no
  Node, and the executable would stop being the one download.
- Trusting the HTTPS download alone. A tampered mirror or cache would pass,
  where the signed checksums stop it.
- Fetching the keyring during the build. The build would trust any key the
  network returns, where the kept keyring names the signers.

## Consequences

- A file carrying Node grows by about 40 MB. The glibc amd64 Mercury Demo
  grows from about 125 MB to about 166 MB.
- `node: true` and a major follow new releases, so a rebuild can change the
  version. An exact version pins it.
- An unpacked Node release stays in the cache until `clean` removes it.
- On a musl file the three commands exit 1 and name the glibc file.

## Updating the keyring

A new Node releaser signs with a key the kept keyring lacks. The build then
stops with a message naming `release-keys.asc` and nodejs/release-keys. The
update replaces the file with the current export:

```sh
git clone --depth 1 https://github.com/nodejs/release-keys /tmp/release-keys
gpg --no-default-keyring --keyring /tmp/release-keys/gpg-only-active-keys/pubring.kbx \
    --armor --export > launcher/internal/node/release-keys.asc
```

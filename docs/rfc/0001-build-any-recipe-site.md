# RFC 0001: Drupack builds any recipe site

Proposed on 2026-09-22. Not implemented.

## Goal

A Drupal site owner points Drupack at their composer project and gets a single
executable of their site. They choose the platforms and, on Linux, the C library.

## Context

Drupack builds one site, Mercury Demo. The engine and the site share
`application/`, and five places name Mercury:

| Place | What it hardcodes |
|---|---|
| `Dockerfile:39-41` | the seed `site:install` of `recipes/mercury_demo`, `pm:enable mcp_tools`, the `automatic_updates` uninstall |
| `application/launch.php:197` | the default site name `Drupal Mercury Demo` |
| `application/launch.php:670` | the recipe path for a MySQL or PostgreSQL first start |
| `application/launch.php:821` | `pm:enable mcp_tools` |
| `runtime/entrypoint.go:54` | the help text naming the default site name |

`build/install-translations.php:45` fixes five languages. The launcher names its
cache root `Drupack` in `launcher/internal/runtime/root_unix.go:42` and
`root_windows.go:39`.

The build already keeps the site-specific part apart from the rest:

- A runtime depends on the site only through `runtime/php-extensions.txt`.
- ADR 0018 tags each builder image by its inputs, and a runtime job pulls a
  published image in 78 seconds instead of compiling PHP for 18 to 25 minutes.
- The packer takes the application payload and each runtime as separate inputs.

## Proposal

### What a site brings

A site repository holds a composer project, its recipe and a `drupack.yml`
beside `composer.json`:

```yaml
name: mysite
port: 7300
recipe: recipes/my_site
site_name: My Site
languages: [fr]
smoke_paths: [/, /about]
```

| Field | Required | Meaning |
|---|---|---|
| `name` | yes | executable name, asset prefix and cache directory; a Minted segment |
| `port` | no | default listen port, 7225 when unset |
| `recipe` | yes | recipe directory, relative to the project root after `composer install` |
| `site_name` | yes | name a first start gives the site |
| `languages` | no | translations to bundle, none when unset |
| `extensions` | no | PHP extensions added to the engine list, from slice 2 |
| `smoke_paths` | no | paths the suite requests and expects a 200 from, `/` when unset |

The build validates `drupack.yml`, since the site author sets it. A wrong field
stops the build and names the field.

A site's own post-install steps live in its recipe. Drupack runs no site hook.

### What the engine still does

- Both install paths uninstall `automatic_updates` and `package_manager`.
  `automatic_updates` 4.1.0 stalls a cron request for 240 seconds, and the
  Application root is read-only, which `package_manager` cannot work against.
- The `mcp_tools` enable goes. It does not work today and no site needs it.
- `DRUPACK_*` variables keep their names, so documentation and support stay shared.

### The build script

`build/drupack-build` runs a whole build on one host and holds all build logic:

```sh
git clone --depth 1 -b 0.3.0 https://github.com/tresbientech/drupack
./drupack/build/drupack-build --site . --platform linux-amd64 --libc both --output dist
```

It runs four steps:

1. build the application payload from the site
2. pull or build each runtime
3. pack the executable
4. run the conformance suite

Any CI calls it. A caller installs Docker, Go and
Python. On macOS and Windows it also needs the toolchains `CONTRIBUTING.md`
lists.

### The GitHub workflow

`.github/workflows/build.yml` is a `workflow_call` wrapper around the script.
A caller pins it by tag:

```yaml
jobs:
  build:
    uses: tresbientech/drupack/.github/workflows/build.yml@0.3.0
    with:
      platforms: linux-amd64
      libc: glibc
      publish: ${{ github.ref_type == 'tag' }}
    permissions:
      contents: write
      packages: write
      attestations: write
      id-token: write
```

| Input | Values | Default |
|---|---|---|
| `platforms` | comma list of `linux-amd64`, `linux-arm64`, `macos-arm64`, `macos-amd64`, `windows-amd64` | all |
| `libc` | `both`, `glibc`, `musl`; Linux only | `both` |
| `publish` | `true` creates a GitHub Release in the caller's repository | `false` |

Every run uploads the executables as workflow artifacts. A publishing run adds
what Drupack's own release carries: versioned and unversioned asset names,
`checksums.txt`, `release.json`, a CycloneDX SBOM and provenance attestations.

A Linux executable with one libc carries one runtime. ADR 0015 already runs it
without reading `DRUPACK_LIBC`.

### Identity and versions

The packer takes `name`, `port` and the site version and writes them into the
launcher manifest. The site version is the caller's tag, or `dev-<sha>` off a tag.

- The cache root becomes `<user cache>/<name>`, so `clean` on one site leaves
  another site's runtime in place.
- `--version` prints `mysite 1.4.0 (drupack 0.3.0, glibc)`.
- The Version record holds both versions. A start refuses to serve when either
  is older than the record. This closes a site downgrade over a schema its
  newer release updated.

### PHP extensions

The engine list stays the base: `Core`, the database drivers, `opcache` and the
rest `runtime/php-extensions.txt` names. `drupack.yml` `extensions:` adds names.

A site with no additions pulls the published runtime images. A site with
additions misses the builder tag. The build compiles the image once and pushes
it to `ghcr.io/<caller>/drupack-builder`, so later runs pull it.

### Tests

The conformance suite drops its 10 Mercury references across five case files.
It reads the site name and the smoke paths from `drupack.yml`. Every caller
build runs the whole engine suite on its own executable.

### Repository layout

- Engine PHP, the Caddyfile, `settings.php` and `support/` move out of `application/`.
- Mercury moves to `examples/mercury-demo/`, with its composer project and a `drupack.yml`.
- `release.yml` builds Mercury by calling `build.yml`, so every run of this
  repository exercises the public contract.
- The README download links do not change.

## Slices

Slice 1 lets a stranger build and test a Linux executable of their recipe site
on GitHub, under its own name:

1. The engine and site split, `drupack.yml`, the `mcp_tools` removal.
2. `name` and `port` in the packer, launcher and cache root.
3. `drupack-build` for Linux targets.
4. The `build.yml` wrapper with the opt-in release.
5. The site-agnostic conformance suite and `smoke_paths`.

In slice 1 the extension list is fixed. A lock that declares an unlisted
extension fails the build, naming the extension.

Slice 2 adds the rest:

- macOS and Windows in `drupack-build`
- `extensions:` and the caller's builder registry
- the two-version gate

## Considered options

Delivery:

- A template repository. Each copy carries the whole engine, and each engine fix
  needs a manual merge into every copy.
- A builder container. It builds Linux only, and macOS and Windows still need
  native hosts.
- A GitHub-only workflow. A Forge or GitLab caller would have no path until a port.

Identity:

- Full rebranding, variable prefix included. The 13 `DRUPACK_RUNTIME_*`
  variables cross five languages, a refactor ahead of any template.
- Every build stays `drupack`. Two sites share a cache and a port, and `clean`
  on one removes the other's runtime.

Site hooks:

- A `post_install:` Drush list. It duplicates what a recipe expresses.
- A PHP hook file. It runs arbitrary code on both install paths.

Extensions:

- The site owns the whole list. A site could drop `pdo_sqlite`, which the SQLite
  first start needs.
- A fixed set forever. Sites needing `gmp` or `imagick` could not build.

Output:

- Artifacts only. Every caller would rebuild checksums, SBOM and provenance or skip them.
- Smoke tests only on caller builds. A site that breaks under PostgreSQL would ship.

Versions:

- The engine version alone. An older site build would open a database a newer
  one updated.
- The site version alone. An older engine could open Site data a newer one laid
  out differently.

## Consequences

- `drupack.yml`, the workflow inputs and the conformance suite become a public
  contract. A breaking change to any of them needs a major engine version.
- A caller with the default extension set packs in minutes. A caller with
  additions pays one 18 to 25 minute compile per libc and input set.
- Callers' registries accumulate builder tags, as ADR 0018 noted for this one.
- `README.md` and `CONTEXT.md` drop "First start enables Local MCP Tools".
- A site author needs a guide: `drupack.yml`, the workflow snippet and the host
  tooling for `drupack-build`.

## Open questions

- How `build.yml` checks out the engine at its own ref. The `github` context in
  a called workflow names the caller. The OIDC `job_workflow_ref` claim names
  the called workflow.
- Whether the `automatic_updates` loop from 4.1.0 still exists in the current
  release. If it is fixed, the uninstall keeps only the read-only reason.
- Whether the Mercury example keeps `drupal/mcp_tools` and `drupal/mcp_server`
  in its `composer.json` once nothing enables them.
- How the engine version maps to the contract: semver from 1.0, or a separate
  contract version inside `drupack.yml`.

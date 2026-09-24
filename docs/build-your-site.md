# Build your own site

Drupack builds a Linux executable of any Drupal site from its Composer project and
a recipe. The build runs `drupack-build` inside the engine's job image,
`ghcr.io/tresbientech/drupack-build:VERSION`. The image carries the engine, PHP,
Composer, Go, Python and all four Linux runtimes, and needs no Docker daemon.

The site repository can be private. The examples use a site named `acme`.

## What the site repository holds

- `composer.json` and `composer.lock`, the site's Composer project. Its
  `extra.drupal-scaffold.locations.web-root` names the docroot, such as `web/`
  or `docroot/`, and the build refuses a project that leaves it unset.
- `drupack.yml`, the site contract.
- `tests/`, optional conformance cases the suite runs after the engine's own.

[`examples/mercury-demo`](../examples/mercury-demo) is a complete site repository.

The build copies into the executable every file git tracks or would track,
except its own output and work directories. List credentials such as
`auth.json` or `.env` in `.gitignore`. A site outside any git checkout is
copied whole. In a checkout git cannot read, the build stops with git's error.

`drupack.yml` fields:

```yaml
name: acme                 # executable name and cache directory, required
recipe: recipes/acme_site  # the recipe the build seeds and a first start installs
site_name: Acme            # the site name the install sets, required
settings: acme.php         # PHP the generated settings.php requires last
port: 7225                 # the port the site listens on
languages: [fr, de]        # translations the build fetches
smoke_paths: [/, /about]   # paths the suite expects a 200 from, / by default
platforms: [linux-amd64]   # targets: linux-amd64, linux-arm64
libc: both                 # C library of each runtime: both, glibc or musl
```

`settings` names a PHP file in the site. The build stops when the file is
absent or git ignores it.

`platforms` defaults to `[linux-amd64]` and `libc` to `both`. `both` packs two
runtimes in one file, and the executable picks one per host. The
`drupack-build` flags `--platform` and `--libc` override the file, and so do
the GitHub workflow's inputs.

A build writes `acme-linux-amd64` and `site.json` to its output directory.

## A site without a recipe

A site that leaves `recipe` out builds no seed. Its executable serves a MySQL
or PostgreSQL database that already holds the site:

```sh
./acme-linux-amd64 --database mysql --db-host 127.0.0.1 --db-port 3306 \
    --db-name acme --db-user acme --db-password PASSWORD
```

The first start keeps that site, enables nothing and keeps its administrator
account. A SQLite start refuses, and so does a start on a database that holds
no site. The build's conformance suite reports each case that needs a seed as
skipped, with the reason "the site has no recipe".

## Private Composer packages

`composer install` reads the `COMPOSER_AUTH` environment variable. It holds the
content of an `auth.json`:

```json
{"http-basic": {"git.example.com": {"username": "ci", "password": "TOKEN"}}}
```

Store it as a CI secret named `COMPOSER_AUTH`. Each section below passes it to the
build. A site with public packages only leaves the secret unset.

## GitHub

A job calls the engine's reusable workflow at an engine tag:

```yaml
on:
  push:
    tags: ['*']

jobs:
  build:
    permissions:
      contents: write
      id-token: write
      attestations: write
    uses: tresbientech/drupack/.github/workflows/build.yml@0.3.0
    with:
      publish: true
    secrets:
      COMPOSER_AUTH: ${{ secrets.COMPOSER_AUTH }}
```

The workflow reads the engine commit from the ref after `@`, so an engine
upgrade changes that one line. It needs `id-token: write` to read that ref.

Inputs:

- `site`: the site's directory, `.` by default.
- `platforms`: comma-separated targets, `drupack.yml`'s `platforms` when unset.
- `libc`: `both`, `glibc` or `musl`, `drupack.yml`'s `libc` when unset.
- `publish`: on a tag run, create a release.

Every run uploads the executables as the `executables` artifact. With `publish`,
a tag run creates a release in the site repository. It holds the executables,
`checksums.txt`, a CycloneDX SBOM and build provenance. A build without `publish`
needs `contents: read` and `id-token: write` only.

## Gitea

Gitea Actions gives a job no OIDC token, so a Gitea site runs `drupack-build`
in a job of its own. The image line names the engine version.

`.gitea/workflows/build.yml`:

```yaml
name: Build

on:
  push:
    branches: [main]
    tags: ['*']

jobs:
  build:
    runs-on: ubuntu-latest
    container:
      image: ghcr.io/tresbientech/drupack-build:0.3.0
    defaults:
      run:
        shell: bash
    steps:
      - uses: actions/checkout@v4

      - name: Build the executables
        env:
          COMPOSER_AUTH: ${{ secrets.COMPOSER_AUTH }}
        run: |
          version=dev-${GITHUB_SHA:0:12}
          if [ "$GITHUB_REF_TYPE" = tag ]; then version=$GITHUB_REF_NAME; fi
          git config --global --add safe.directory "$GITHUB_WORKSPACE"
          drupack-build --site . --site-version "$version" \
            --output /tmp/drupack/dist --work /tmp/drupack/work

      - uses: actions/upload-artifact@v3
        with:
          name: executables
          path: /tmp/drupack/dist

      - name: Publish a release
        if: github.ref_type == 'tag'
        env:
          TOKEN: ${{ secrets.GITEA_TOKEN }}
        working-directory: /tmp/drupack/dist
        run: |
          sha256sum -- *-linux-* >checksums.txt
          api=$GITHUB_SERVER_URL/api/v1/repos/$GITHUB_REPOSITORY
          id=$(curl -fsS -X POST -H "Authorization: token $TOKEN" -H 'Content-Type: application/json' \
            -d "{\"tag_name\": \"$GITHUB_REF_NAME\", \"name\": \"$GITHUB_REF_NAME\"}" "$api/releases" \
            | python3 -c 'import json, sys; print(json.load(sys.stdin)["id"])')
          for file in *-linux-* checksums.txt; do
            curl -fsS -H "Authorization: token $TOKEN" -F "attachment=@$file" \
              "$api/releases/$id/assets?name=$file" >/dev/null
          done
```

Notes on the job:

- The runner must start job containers, which the default `act_runner`
  configuration does. `act_runner` starts JavaScript actions with the image's
  own `node`, which the job image carries.
- A container job runs steps with `sh` unless `shell: bash` says otherwise.
- The job uploads with `upload-artifact@v3`, which Gitea's artifact storage accepts.
- The checkout belongs to another user than the container's root, and git
  refuses to list its files until `safe.directory` names it.
- The release step uses the job's automatic `GITEA_TOKEN`. A release holds the
  executables and `checksums.txt`, with no SBOM or provenance.

Tested on Gitea 1.27.

## Any other CI

The build is one command in the job image, from the site repository's root:

```sh
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/site" -w /site -e COMPOSER_AUTH \
    ghcr.io/tresbientech/drupack-build:0.3.0 \
    drupack-build --site . --output dist
```

A CI that runs jobs in a container image uses the image and runs the
`drupack-build` line alone. `drupack-build -help` lists every option.

The build runs the conformance suite on the executable for its own platform. The
cases that need a Docker daemon skip inside the job image.

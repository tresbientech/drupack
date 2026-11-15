# Contributing to Drupack

The canonical repository is the drupal.org project [`drupack`](https://git.drupalcode.org/project/drupack). GitHub holds a copy that builds and publishes the executables, and takes no pull requests.

Read before a change:

- `CONTEXT.md` for the vocabulary
- `docs/cli.md` for what the product promises
- `docs/adr/` for the decisions behind the current shape

## Issues and merge requests

- A subject of work starts with an issue in the [drupal.org issue queue](https://git.drupalcode.org/project/drupack/-/work_items). Search the queue before a new one.
- A comment `/do:fork` on the issue creates its fork, `issue/drupack-<issue number>`. A personal fork is never used.
- The branch is named `<issue number>-<slug>`, is pushed to that fork, and holds one commit.
- That commit's subject uses Drupal core's format, `fix: #3636009 Refuse the options drush cannot use`. The type is `fix`, `feat`, `task`, `refactor`, `perf`, `test`, `docs`, `ci` or `chore`.
- The merge request's title is the commit subject. A maintainer merges it on drupal.org by fast-forward, and closes the issue.
- A commit that belongs to no issue keeps a plain imperative subject.
- A commit message, or a document outside `docs/adr/`, states a decision in its own words and cites no ADR number.

## Build

Build on Linux `amd64` or `arm64` with Docker and BuildKit, Go, `python3` and `zstd`. Build the two builder images first. Each compiles PHP from source and takes over an hour. Docker keeps them, so later builds reuse them.

```sh
bash runtime/builder/build-builder.sh musl drupack-builder-musl:local
bash runtime/builder/build-builder.sh gnu drupack-builder-gnu:local
bash build/test/qa.sh
```

`build/test/qa.sh` builds the `job` image, builds Drupacked Demo and the engine executable inside it, and runs the tests. `drupack-build -help` lists the options of a hand build. The macOS and Windows builds run on their own hosts; `.github/workflows/release.yml` shows their commands and tools.

## Tests

A conformance case asserts what only a live run shows: an HTTP answer, a served certificate, a process, a database or an OS. A choice a pure function makes, such as a refusal message or a start plan, gets a unit test instead.

Every Python script in `build/`, `runtime/` and `tests/` imports the standard library alone.

```sh
bash build/test/go-tests.sh
bash build/test/unit-tests.sh
DRUPACK_TEST_ENGINE=dist/engine/drupack-linux-amd64 python3 tests/conformance dist/drupacked-demo-linux-amd64 test-results/conformance --site-tests demo/tests
```

## Development loop

`build/dev/dev-server.sh` starts the built demo and lays `application/` over it on each start, so a PHP change applies without a build. A change under `launcher/` needs a build.

```sh
bash build/dev/dev-server.sh ./dev-data 7225
```

## Releases

Never push a version tag. To release `main`, dispatch `.github/workflows/upstream.yml` on GitHub with `version`, such as `1.0.1`. Its candidate run is the release QA, so no local `build/test/qa.sh` run comes before it.

The run's `node` job posts the drupal.org release form as `nod_bot`. The `DRUPAL_ORG_SESSION` secret holds that session, which lasts about four weeks. `.github/workflows/release-node.yml` fails each morning once it expires. To renew it:

1. Log in on `www.drupal.org` as `nod_bot` and open the [release form](https://www.drupal.org/node/add/project-release/3624016).
2. In the developer tools' Network tab, copy the whole `Cookie` request header of that request.
3. Store it with `gh secret set DRUPAL_ORG_SESSION --repo tresbientech/drupack`, which asks for the value.
4. Close the window without logging out, since a logout ends the session.

When the `node` job fails, create the release by hand from the `notes` job's summary.

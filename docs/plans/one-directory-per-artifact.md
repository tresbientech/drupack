# Plan: one directory per built artifact

Goal: every top-level directory names one built artifact, and the word
`runtime` means one thing. Payoff: the vendor symlink and its three
explanations go, `go.mod` matches its path, and a reader finds a file by what
it builds.

## What was decided

`docs/adr/0017-one-directory-per-artifact.md` holds the decision, the rejected
alternatives and the reference counts.

One correction to the shape agreed in the interview. `site-templates.php` goes
to `build/`, not `application/`. Drupal reads it from
`web/sites/default/site-templates.php`, inside the generated `web/` tree that
git ignores. The build must place it, the same as `install-translations.php`.

Phases run in order. Each one ends green before the next starts. ADRs keep the
paths they were written with, because they record what was decided then.

## Phase 1: free the name `application`

- [x] `release.yml:285-287`: the `mkdir` and both `docker cp` targets become
      `dist/payload`.
- [x] `release.yml:292,293,319,320,389,390`: the artifact path becomes
      `dist/payload`.
- [x] `release.yml:261,262,298,363`: the job named `application` becomes
      `payload`.
- [x] `release.yml:343,413`: both platform builds receive `dist/payload`.
- [x] `packaging/windows/build.ps1:2,13,121,177`: `$ApplicationDirectory` and
      every `$applicationRoot` use become `$PayloadDirectory` and `$payload`.
- [x] `packaging/macos/build.sh:4,5`: `APPLICATION_DIRECTORY` and `$application`
      become `PAYLOAD_DIRECTORY` and `$payload`.
- [x] `CONTRIBUTING.md:34-42`: the export block and the `build.ps1` call.
- [x] `.gitignore`: drop `/application/`. `/dist/` already covers the new path.
- [x] `git grep -n 'ApplicationDirectory\|APPLICATION_DIRECTORY'` returns
      nothing.

## Phase 2: `application/`

- [x] `git mv drupal application`.
- [x] `git mv` `runtime/Caddyfile`, `runtime/launch.php`, `runtime/php.ini`,
      `runtime/settings.php`, `runtime/cacert.pem` and `runtime/support` into
      `application/`.
- [x] Delete the `runtime/vendor` symlink. `composer install --working-dir=application`
      now fills `application/vendor`.
- [x] `git mv tests/unit application/tests`. Rename `windows_paths.php`,
      `previous_copies.php` and `site_data_public_stream.php` with a `_test`
      suffix.
- [x] The 6 `require __DIR__` calls in `application/tests/` become sibling
      paths.
- [x] `Dockerfile:26,30,32`: `drupal/` and `runtime/` become `application/`.
- [x] `.gitignore`: `/drupal/vendor/`, `/drupal/web/` and `/drupal/recipes/`
      become `/application/…`. Drop `/runtime/vendor` and the comment above it.
- [x] `.dockerignore`: the two `drupal/composer.*` lines and `!runtime/` become
      `!application/`. Drop the `runtime/vendor` exclusion and its comment.
- [x] `packaging/macos/build.sh:72` and `packaging/windows/build.ps1:147,154`:
      the `php.ini` and `cacert.pem` sources become `application/`.
- [x] `docker build --target app -t drupack-build .` succeeds.
- [x] Each file in `application/tests/` passes under `./dist/drupack php-cli`.

## Phase 3: `runtime/`

- [x] `git mv` into a new `runtime/`: `packaging/entrypoint.go`,
      `packaging/embed.sh`, `packaging/build-builder.sh`,
      `packaging/extensions-list.sh`, `packaging/php-extensions.txt`,
      `packaging/php-extension-libs.txt`, `packaging/check-extensions.py`.
- [x] `Dockerfile:6,8,56,57,58,64,65,66`: the `packaging/` paths become
      `runtime/`.
- [x] `release.yml:74,86,119`: the check, the builder script and the comment.
- [x] Path comments inside `embed.sh:5`, `extensions-list.sh:5-6`,
      `build-builder.sh:5,19,31` and `check-extensions.py:2,3,82`.
- [x] `packaging/macos/build.sh:17,18,54` and
      `packaging/windows/build.ps1:40`: the extension lists and
      `entrypoint.go`.
- [x] `python3 runtime/check-extensions.py` passes.
- [ ] `bash runtime/build-builder.sh musl drupack-builder-musl:local` reaches
      its first compile step.

## Phase 4: `launcher/`

- [x] `git mv packaging/launcher launcher`. The path now matches the module
      `git.tresbien.tech/tresbientech/drupack/launcher`.
- [x] `Dockerfile:90`: `COPY launcher /src/launcher`.
- [x] `release.yml:158,165,314,384`: the `go.mod` comment and three
      `working-directory` keys.
- [x] Path comments inside `launcher/main.go:2` and `launcher/payload.go:6`.
- [x] `packaging/macos/build.sh:75,78`: `$repository/launcher`.
- [x] `packaging/windows/build.ps1:173`: `..\..\launcher`. The script keeps
      its depth when phase 5 moves it, so the path stays correct.
- [x] `cd launcher && go test ./...` passes.

## Phase 5: `build/`

- [x] `mkdir -p build/dev`. `git mv` needs the target directory.
- [x] `git mv packaging/macos build/macos`.
- [x] `git mv packaging/windows build/windows`.
- [x] `git mv packaging/app-payload.sh packaging/install-translations.php
      packaging/site-templates.php build/`.
- [x] `git mv packaging/dev-server.sh packaging/dev-entry.sh build/dev/`.
- [x] `Dockerfile:28,33,45`: the three remaining `packaging/` paths.
- [x] `release.yml:330,343,413`: the cache key and both platform build calls.
- [x] `build/dev/dev-server.sh:4,30`: the usage line and the bind mount source.
- [x] `.gitignore`: drop the unused `/build/` line.
- [x] `.dockerignore`: `!packaging/` and `!packaging/**` become `!build/` and
      `!build/**`.
- [x] `packaging/` no longer exists.
- [ ] `docker build --target artifact --output type=local,dest=dist .` succeeds.
- [ ] `python3 tests/conformance ./dist/drupack test-results/conformance` passes.

## Phase 6: docs

- [x] `CONTRIBUTING.md`: the build, test, dev loop and launcher sections carry
      the new paths.
- [x] `CONTRIBUTING.md`: one line names `Dockerfile` as the Linux platform
      build, peer of the two scripts in `build/`.
- [x] `CONTRIBUTING.md:116`: the doc list names `docs/reviews/`, deleted once
      the plan acting on it ships.
- [x] `README.md` and `docs/backlog.md`: any moved path.
- [x] `docs/plans/application-review-follow-ups.md` and
      `docs/plans/php-extension-allowlist.md`: any moved path.
- [x] `docs/plans/test-architecture-followups.md:3`: the link to
      `test-architecture.md`, a file that no longer exists.
- [x] `git grep -n 'packaging/' -- '*.md' ':!docs/adr'` returns nothing.

## Found during execution

The plan missed five reference sites. All are done.

- `application/` already existed as the untracked payload directory, so
  `git mv drupal application` nested the Composer project. The payload files
  moved to `dist/payload/` and the project moved up one level.
- `tests/conformance/harness.py` holds the allowlist and launcher source paths
  as constants at lines 50 and 183.
- `application/tests/launch_test.php:385` reads `entrypoint.go` by path.
- `application/Caddyfile`, `application/launch.php` and
  `launcher/cmd/pack/main.go` name moved files in comments.
- `docs/cli.md` and the three live plans name moved files.

The count in the ADR is the measured one: 18 lines in the `Dockerfile`, 26 in
`release.yml`, 7 path references in the PHP unit files.

## Verified

- `docker build --target app` succeeds. The composer install layer was reused,
  which proves the lock-file copy still caches. `dump-autoload --optimize`
  resolved `Drupack\Support\` to `application/support/` in place.
- The payload carries `./support/` and no `./tests/`, at 229 MB.
- The four PHP unit files pass: 42, 5, 3 and 9 checks.
- `go test ./...` in `launcher/`: 75 tests in 3 packages.
- `python3 runtime/check-extensions.py`: 16 declared extensions, all allowed.
- `python3 -m unittest discover -s tests/conformance -p 'test_harness.py'`: 8
  tests.

## Not verified here

The three boxes left open need `drupack-builder-musl:local` and
`drupack-builder-gnu:local`, which this machine does not hold. Each takes over
an hour to build.

## Noticed, not in scope

- `packaging/dev-server.sh:13` tells the reader to run
  `docker build --target build`. The `Dockerfile` has no `build` stage, and
  `CONTRIBUTING.md` names `--target app`.
- `docs/plans/` holds four plans. `CONTRIBUTING.md:116` says a plan is deleted
  once its work ships.

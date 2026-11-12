# A daily run releases on green CI and posts the drupal.org release form

## Context

Drupack pins PHP, FrankenPHP, Go and Windows extensions. A new upstream version must reach readers without the owner noticing it. A release builds five platforms and runs the whole suite, so the graph must build each thing once. drupal.org has no API that creates a release node, and Packagist takes each `drupal/drupack` version from one.

## Decision

### The plan

- `build/release-plan.py` owns the release plan. `plan(event, targets, fetch)` returns ten values. They are the version, `build`, the candidate run, the image tags, one matrix per platform family, the two test row lists and the runtimes to build.
- `fetch` is the one port to GitHub. A failed call stops the gate.
- A tag whose candidate run succeeded publishes that run's files. Other tags and dispatches cover every platform. A main push covers Linux amd64 when it touched a path an executable carries.

### The graph

- `runtime/builder-tag.sh` digests a builder image's inputs into a tag under `ghcr.io/tresbientech/drupack-builder`. A runtime job pulls that tag. A miss builds the image in the job, and `builder-push` pushes it. A fork builds and skips the push.
- The `payload` job builds the application once. Every platform packs its output.
- macOS and Windows build and pack in one job, then upload the files as a `suite-*` artifact. The test jobs download it and run one `DRUPACK_SHARD` share each.
- Intel macOS runs three shares, macOS arm64 two, each Windows architecture five, and Linux two per architecture. Windows arm64 tests the files the amd64 host built.
- The `qa` job runs `build/qa.sh` in three phases, `demo`, `variant` and `host`, one per job. The `host` phase also runs `govulncheck`.
- `publish` needs the test jobs.

### The upstream run

- `.github/workflows/upstream.yml` runs on GitHub, and drupal.org's pipeline schedule dispatches it daily. A dispatch with `version` releases `main` under that version with no bump.
- `build/upstream.py` moves each pin to its newest version inside its line. The pins are PHP, FrankenPHP and its commit, the static-php-cli v3 head, the Windows vcpkg commit, each Windows PECL extension with its zip checksum, Go, and the Go tools. PHP waits until php.net lists both Windows zips.
- A newer major prints as `MAJOR` and is never written.
- The run stops when no pin moves.
- The candidate is the bump on `main` HEAD, on the GitHub-only branch `upstream/<fingerprint>`. The fingerprint is the SHA-256 of the bump's diff. A hand version uses `candidate/<version>`.
- Each candidate moves `build/demo-pin.sh` to the Drupacked Demo's latest release that descends from the pin.
- A candidate that failed is not built again unless the dispatch sets `force`. A named version always builds.

### The candidate and the tag

- The job dispatches `release.yml` on every platform with `version`. The run is named `Release candidate VERSION`.
- `build/wait-for-run.sh` finds the run by the candidate's commit and polls every two minutes. It names each failed job and, once the run completes, reruns the failed jobs once.
- The candidate's `qa` jobs are the release's QA. No local run precedes a release.
- On green, one atomic push sends `main` as a fast-forward and the next tag to drupal.org. A second push sends both to GitHub. The job then deletes the branch.
- The tag run's gate finds the candidate run at the tag's commit. When it exists with live artifacts, every build job skips and `publish` downloads its files. Otherwise the tag builds every platform.
- A `tag` job waits for the tag run with the same script. No person pushes a version tag.

### The drupal.org release form

- The `notes` job writes the release notes with `drupalorg-cli`, pinned by version and SHA-256, from the commits that name an issue. Bug gives "Bug fixes" and Feature gives "New features". The script never ticks "Security update".
- The `node` job posts the form as `nod_bot` with `build/release-node.py`, once the tag run has published. It uses Python's standard library and the user agent `drupack-release`.
- The form has two steps. The first picks the tag and saves nothing. The second takes the notes and the release type. The script sends back every field the form holds and sets three: the tag, the notes and the type.
- `DRUPAL_ORG_SESSION` holds the `Cookie` header of a browser logged in as `nod_bot`. The cookie goes to `www.drupal.org` only. A redirect that leaves that site stops the script.
- The `node` job runs this repository's code alone. The `notes` job runs `drupalorg-cli` and holds no secret.
- `release-node.yml` goes red while Packagist lists no release for the highest tag, and when the session fails to open the form. A session lasts about four weeks.

## Reason

A builder image tagged by its inputs answers the objection that one image cannot serve several extension sets: another list is another tag. Testing in shares keeps the wall clock near the longest share.

A script plans the run so a unit test checks the plan. A plan written in workflow expressions shows its mistakes in a finished run alone. A failed lookup that read as no candidate would rebuild what it could have published.

The upstream run lives on GitHub, where the builds run. Testing the candidate before the tag moves means a red platform never ships, and the tag run publishes the candidate's files.

drupal.org staff accept a plain HTTP post with an honest user agent for limited use. They advise against a browser driver. A maintainer's own cookie would act as a person on all of drupal.org. A release cannot be deleted, so the post follows a green tag run and is sent once.

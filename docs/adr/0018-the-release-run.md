# A daily run releases on green CI and posts the drupal.org release form

## Context

Drupack pins PHP, FrankenPHP, Go, Windows extensions and Composer. A new upstream version must reach readers without the owner noticing it. A release builds five platforms and runs the whole suite, so the graph must build each thing once. drupal.org has no API that creates a release node, and Packagist takes each `drupal/drupack` version from one.

## Decision

### The plan

- `build/release/release-plan.py` owns the release plan. It returns the version, the candidate run, the image tags, one matrix per platform family, the test rows and the runtimes to build.
- `fetch` is the one port to GitHub. A failed call stops the gate.
- A tag whose candidate run succeeded publishes that run's files. Other tags and dispatches cover every platform. A main push covers Linux amd64 when it touched a path an executable carries.

### The graph

- A builder image takes a tag that digests its inputs. A runtime job pulls that tag, and a miss builds the image in the job. A fork builds and skips the push.
- The `payload` job builds the application once. Every platform packs its output.
- macOS and Windows build and pack in one job, then upload the files as a `suite-*` artifact. The test jobs download it and run one `DRUPACK_SHARD` share each.
- `publish` needs the test jobs.

### The upstream run

- `.github/workflows/upstream.yml` runs on GitHub, and drupal.org's pipeline schedule dispatches it daily. A dispatch with `version` releases `main` under that version with no bump.
- `build/release/upstream.py` moves each pin to its newest version inside its line. PHP waits until php.net lists both Windows zips.
- A newer Composer release is written only after its `composer.phar.sig` verifies against a key kept in the repository.
- A newer major is never written.
- The run stops when no pin moves.
- The candidate is the bump on `main` HEAD, on a GitHub-only branch named by the SHA-256 of the bump's diff. A hand version names its branch by the version.
- Each candidate moves `build/release/demo-pin.sh` to the Drupacked Demo's latest release that descends from the pin.
- A candidate that failed is not built again unless the dispatch sets `force`. A named version always builds.

### The candidate and the tag

- The job dispatches `release.yml` on every platform with `version`. The run is named `Release candidate VERSION`.
- `build/release/wait-for-run.sh` finds the run by the candidate's commit and polls it. It names each failed job and, once the run completes, reruns the failed jobs once.
- The candidate's `qa` jobs are the release's QA. No local run precedes a release.
- On green, one atomic push sends `main` as a fast-forward and the next tag to drupal.org. A second push sends both to GitHub. The job then deletes the branch.
- The tag run's gate finds the candidate run at the tag's commit. When it exists with live artifacts, every build job skips and `publish` downloads its files. Otherwise the tag builds every platform.
- A `tag` job waits for the tag run with the same script. No person pushes a version tag.

### The drupal.org release form

- The `notes` job writes the release notes with `drupalorg-cli`, pinned by version and SHA-256, from the commits that name an issue. Bug gives "Bug fixes" and Feature gives "New features". The script never ticks "Security update".
- The `node` job posts the form as `nod_bot` with `build/release/release-node.py`, once the tag run has published.
- `DRUPAL_ORG_SESSION` holds the `Cookie` header of a browser logged in as `nod_bot`. The cookie goes to `www.drupal.org` only. A redirect that leaves that site stops the script.
- The `node` job runs this repository's code alone. The `notes` job runs `drupalorg-cli` and holds no secret.
- `release-node.yml` goes red while Packagist lists no release for the highest tag, and when the session fails to open the form. A session lasts about four weeks.

## Reason

A builder image tagged by its inputs answers the objection that one image cannot serve several extension sets: another list is another tag. Testing in shares keeps the wall clock near the longest share.

A script plans the run so a unit test checks the plan. A plan written in workflow expressions shows its mistakes in a finished run alone. A failed lookup that read as no candidate would rebuild what it could have published.

The engine ships the pinned `composer.phar`. Its bump checks the SHA-384 signature `composer self-update` checks, against a key kept in the repository, so a tampered download server cannot pass.

The upstream run lives on GitHub, where the builds run. Testing the candidate before the tag moves means a red platform never ships, and the tag run publishes the candidate's files.

drupal.org staff accept a plain HTTP post with an honest user agent for limited use. They advise against a browser driver. A maintainer's own cookie would act as a person on all of drupal.org. A release cannot be deleted, so the post follows a green tag run and is sent once.

# A daily run releases on green CI and posts the drupal.org release form

## Context

Drupack pins PHP, FrankenPHP, Go and its modules, Composer and the CA bundle. static-php-cli links about 40 C libraries into every platform's file, each at its newest release. A new upstream version must reach readers without the owner noticing it. A release builds five platforms and tests each one, so the graph must build each thing once. drupal.org has no API that creates a release node, and Packagist takes each `drupal/drupack` version from one.

## Decision

### The plan

- `build/release/release-plan.py` owns the release plan. It returns the version, the candidate run, the image tags, one matrix per platform family, the test rows and the runtimes to build.
- `fetch` is the one port to GitHub. A failed call stops the gate.
- A push, to main or a tag, first looks for a successful candidate run at its commit. A tag with one publishes that run's files, and a main push with one builds nothing.
- Other tags and dispatches cover every platform. Another main push covers Linux amd64 when it touched a path an executable carries.

### The graph

- A builder image takes a tag that digests its inputs. A runtime job pulls that tag, and a miss builds the image in the job. A fork builds and skips the push. The image holds the toolchain and the compiled PHP that `embed.sh` links: 1.2 GB (glibc) or 1.5 GB (musl) compressed, with a 0.4 GB or 0.6 GB compile layer.
- The `payload` job builds the application once. Every platform packs its output.
- macOS and Windows build and pack in one job, then upload the files as a `suite-*` artifact. The test jobs download it and run one `DRUPACK_SHARD` share or the start cases each.
- Each macOS architecture builds in its own job, from one composite action, so the arm64 test jobs wait for the arm64 build alone.
- macOS arm64 runs the whole suite. macOS amd64 runs the start cases in its build job.
- The Linux amd64 first job lints and runs the Python unit tests. The Go unit tests run once per system: there, in the Windows build and in the macOS arm64 build.
- release.yml runs the `full` suite each Monday on `main`, with QA, under a `dev-` version. The weekly run publishes nothing.
- `publish` needs `sbom`, which needs every build and test job.
- A Linux or macOS runtime's SBOM report adds the libraries its link step names, from their pkg-config records, one component per project. PHP's own report wins where both name a library. A Windows report adds the source library releases from static-php-cli's download record, saved with the compile.
- The SBOM merge drops a scanned component sharing a name and version with a listed one, unless both carry a `purl` and the two differ.
- payload pushes the Linux builder images every runtime leg built, on amd64, one lane per architecture in parallel. After `docker load`, docker uploads a layer before it learns ghcr holds it, so a lane's second image reuses its first image's base layers. `builder-tag.sh` takes each image's machine type as an argument. QA pulls them, so it needs payload.

### The upstream run

- `.github/workflows/upstream.yml` runs on GitHub, and drupal.org's pipeline schedule dispatches it daily. A dispatch with `version` releases `main` under that version with no bump.
- `build/release/upstream.py` moves each pin to its newest version inside its line.
- A newer Composer release is written only after its `composer.phar.sig` verifies against a key kept in the repository.
- A newer curl extract replaces `runtime/cacert.pem` once it matches curl's published SHA-256.
- A newer major of a pin is never written. A library takes the release static-php-cli's own pattern allows, and a newer major can be that release.
- A release starts when something the executables carry moves: PHP, Composer, FrankenPHP, Go, the CA bundle, SQLite, a library or libaom's tag. The run stops when none moves.
- `runtime/builder/library-versions.txt` records the newest release of each artifact static-php-cli links. The pinned static-php-cli resolves the set from the two extension lists and names each release.
- The probe records no artifact at a fixed URL, such as bzip2 1.0.8, and none built from a branch head. `runtime/builder/branch-pins.txt` holds the commit of each branch-head artifact.
- The run moves libaom's line of `branch-pins.txt` to the newest `vX.Y.Z` tag gitiles lists, with the commit the tag names. A newer major counts, as for any library. Gitiles' answer is remote input, and the run checks the tag and the commit before it writes.
- static-php-cli runs in a job of its own, at the commit `main` pins. That job holds no secret and its token reads public content. The upstream job takes the answers as data and checks each name and release.
- The file records what the run saw. A build links the release it finds, and the next run records that one.
- `runtime/builder/spc-sqlite.patch` names SQLite's release as a URL. The run rewrites it from sqlite.org's product list, inside the 3 line.
- A moved `watcher` line writes the same release into the Windows build, which downloads its own copy.
- The ICU archive the Windows build links has no release to record. Its SHA-256 pin in `build.ps1` moves by hand.
- `golangci-lint`, `govulncheck` and the launcher's Go modules move only in a run that releases. The modules stay inside their majors, and a module that needs a newer Go waits.
- The candidate is the bump on `main` HEAD, on a GitHub-only branch named by the SHA-256 of the bump's diff. A hand version names its branch by the version.
- Each candidate moves `build/release/demo-pin.sh` to the Drupacked Demo's latest release that descends from the pin.
- A candidate that failed is not built again unless the dispatch sets `force`. A named version always builds.

### The candidate and the tag

- The job dispatches `release.yml` on every platform with `version`. The run is named `Release candidate VERSION`.
- A bump that moves PHP, Composer, FrankenPHP, Go or the CA bundle runs the `full` suite. Every platform runs every case, except macOS amd64, which runs the start cases in each profile. A bump that moves SQLite, libraries or libaom alone runs `gate`, as a named version does. The demo pin and the docs pin, which every candidate moves, change no suite. Under `gate`, Linux amd64, macOS arm64 and Windows amd64 run every case. Linux arm64, Windows arm64 and macOS amd64 run the start cases.
- The QA demo and host builds run the start cases. The Linux jobs test the same file in full.
- A release's run calls no `build.yml`. `site-build.yml` calls it after each successful push or weekly run on `main`, when ghcr holds the job image `dev-COMMIT` of `main`'s commit. The candidate pushes that image for a release's commit.
- `build/release/wait-for-run.sh` finds the run by the candidate's commit and polls it. It names each failed job and, once the run completes, reruns the failed jobs once.
- The candidate's `qa` jobs are the release's QA. No local run precedes a release.
- On green, one atomic push sends `main` as a fast-forward and the next tag to drupal.org. A second push sends both to GitHub. The job then deletes the branch.
- The gate of the tag run and of the `main` run finds the candidate run at the commit. When it exists with live artifacts, every build job skips, and the tag's `publish` downloads its files. Otherwise the tag builds every platform.
- A `tag` job waits for the tag run with the same script. No person pushes a version tag.

### The drupal.org release form

- The `notes` job writes the release notes with `drupalorg-cli`, pinned by version and SHA-256, from the commits that name an issue. Bug gives "Bug fixes" and Feature gives "New features". The script never ticks "Security update".
- A bump release's notes open with an "Upgraded packages" list: every `bumped` row of the summary in the tag commit's body, as name, old value and new value. The node on drupal.org and the GitHub Release both carry it. `upstream.py` holds the one reader of that table.
- The `node` job posts the form as `nod_bot` with `build/release/release-node.py`, once the tag run has published.
- `DRUPAL_ORG_SESSION` holds the `Cookie` header of a browser logged in as `nod_bot`. The cookie goes to `www.drupal.org` only. A redirect that leaves that site stops the script.
- The `node` job runs this repository's code alone. The `notes` job runs `drupalorg-cli` and holds no secret.
- `release-node.yml` goes red while Packagist lists no release for the highest tag, and when the session fails to open the form. A session lasts about four weeks.

## Reason

A builder image tagged by its inputs answers the objection that one image cannot serve several extension sets: another list is another tag. Testing in shares keeps the wall clock near the longest share.

The macOS amd64 suite took 50 of a full run's 154 macOS minutes, on the same OS arm64 tests, and no fix in the history was specific to Intel. Intel Macs are the least used target, and GitHub retires its last Intel runner image in 2027.

A bump of PHP or FrankenPHP changes every platform's file, so it tests every platform before its tag, macOS amd64 with its start cases. A named version changes Drupack's own code, which the three full platforms run on each system. A library release changes every platform's file, and October 20 to November 20, 2026 held ten of them. Its bump runs `gate` too, which runs every case once on each system. Windows arm64 runs the amd64 file under emulation, and Linux arm64 runs the code Linux amd64 tests. The weekly run shows a defect on Linux arm64 and Windows arm64 within seven days.

A script plans the run so a unit test checks the plan. A plan written in workflow expressions shows its mistakes in a finished run alone. A failed lookup that read as no candidate would rebuild what it could have published.

The engine ships the pinned `composer.phar`. Its bump checks the SHA-384 signature `composer self-update` checks, against a key kept in the repository, so a tampered download server cannot pass.

A release gives readers a new executable, so a tool no executable carries starts none. Go modules publish more often than a run on every platform is worth, so they join a bump that already pays for one.

A library fix once waited for the next PHP or FrankenPHP bump: six releases shipped libpq 18.6 after 18.7 existed. static-php-cli already resolves each library's newest release for the build, so the run asks it and keeps no list of its own.

The upstream job holds the mirror's deploy key and the drupal.org token, and it commits its working tree. Third-party code that ran there could edit a tracked file or read a later step's secret. In its own job, a wrong answer can start a release and nothing else.

The upstream run lives on GitHub, where the builds run. Testing the candidate before the tag moves means a red platform never ships, and the tag run publishes the candidate's files.

drupal.org staff accept a plain HTTP post with an honest user agent for limited use. They advise against a browser driver. A maintainer's own cookie would act as a person on all of drupal.org. A release cannot be deleted, so the post follows a green tag run and is sent once.

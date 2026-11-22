# One extension list drives every builder, and a release carries its trust store

## Context

Three builders compile PHP: Linux, macOS and Windows. Each must ship the extensions Drupal needs and no others. A static PHP build has no system CA bundle, so a release must carry its own.

## Decision

### The allowlist

- `runtime/extensions/php-extensions.txt` lists every shipped extension, one per line, with a trailing comment naming what needs it.
- The three builders, `check-extensions.py` and the conformance suite read that file. None repeats a literal list.
- `check-extensions.py` reads the `ext-*` requirements of a site's lock, from its packages and its platform block, and fails the build when the lock declares a name the file omits.
- The conformance suite asserts that a binary loads exactly the names the file lists, plus the extensions PHP always compiles.
- `tests/conformance/harness.py` holds the facts that differ by platform.
- `build.ps1` hands static-php-cli the list without `pcntl`, `posix` and `password-argon2`, which it does not build on Windows. It runs no load check.
- On Windows the release job's conformance run fails the build for a name the runtime does not load. The publish job needs that job.
- A Drupal update that declares a new extension stops the build until someone adds a line.

### The Linux and macOS builders

- `runtime/builder/builder-inputs.sh` pins the FrankenPHP commit, the PHP version and a static-php-cli v3 commit. `build/release/upstream.py` moves all three.
- `runtime/builder/fetch-spc.sh` checks out that commit, verifies its hash and applies two patches. static-php-cli names SQLite 3.45.2 and passes flags newer releases reject. `spc-sqlite.patch` names a current release, which the upstream run moves.
- static-php-cli is third-party code. Every builder installs its Composer packages with `--no-scripts --no-plugins`, and it runs in no job that holds a secret or pushes.
- `spc-icu.patch` names the file ICU publishes its source under since ICU 78. static-php-cli looks for the older name, which stops at 77.1.
- static-php-cli compiles every other library from the file `runtime/builder/library-versions.txt` records, with its release and SHA-256. The upstream run moves the file to each library's newest release. A release published after the last approved bump stops a build that downloads it.
- Each build downloads `php-embed`, the extensions and the libraries with `--for-packages` and `--no-alt`, the set the upstream run records. It runs `runtime/builder/check-downloads.php` after `spc download` and again after the compile, which downloads what its own options miss.
- The checker stops the build on a source with no line in `library-versions.txt`, another file name or other bytes. It skips branch pins, binaries, and the toolchain sources `llvm-compiler-rt` and `re2c`. A site build with its own extensions downloads the libraries' current releases, so its checker prints each difference and the build goes on.
- static-php-cli builds pthreads4w, libiconv-win, ext-yaml, libaom and libargon2 from a branch head. `runtime/builder/branch-pins.txt` pins each to a commit. A person moves each pin but libaom's, which the upstream run moves. `fetch-branch-pins.sh` checks out each commit. The build passes each checkout as `custom-local` to static-php-cli's download step and to its build step, which downloads again.
- `builder-tag.sh` digests the pins, both lists, the build scripts, both patches, the versions file, the checker and `branch-pins.txt` into a Linux image's tag. The pin checkouts enter the compile container alone, so the image holds none.
- The macOS buildroot is cached under a key that hashes `build/macos/build.sh`, the pins, `fetch-spc.sh` with `fetch-commit.sh`, `branch-pins.txt` with `fetch-branch-pins.sh`, both patches, the versions file, the checker, `extensions-list.sh` and both lists.
- A library release therefore builds a new image and a new buildroot.
- A Linux builder image is FrankenPHP's toolchain image on the pinned RHEL 8 base, plus one layer: the paths `embed.sh` reads from the PHP compile. `build-builder.sh` lists them: PHP's libraries, headers and tools, spc, the Go toolchain and, for musl, zig. Sources, downloads and Go caches stay out: over 80 percent of the compile's bytes.
- The glibc build links against glibc 2.28, the glibc of the RHEL 8 toolchain image. `build/install/install.sh` and self-update take the glibc file on glibc 2.28 or later, and the musl file below it.

### The Windows builder

- `build/windows/build.ps1` runs the same pinned static-php-cli on the runner, at the PHP and FrankenPHP pins of `builder-inputs.sh`. It compiles PHP and its libraries into one static library, and Drupack links FrankenPHP against it.
- static-php-cli compiles no ICU on Windows. It links a prebuilt archive from static-php.dev, which that site replaces in place. `build.ps1` pins the archive's SHA-256 and checks it before the compile. A replaced archive stops the build, and a person moves the pin.
- The link passes the flags static-php-cli's own FrankenPHP link passes on Windows. `build.ps1` holds a copy, which follows static-php-cli when its pin moves. The Windows conformance run checks it.
- The file watcher is a DLL from its own release, pinned by URL and SHA-256. The other builders compile it.
- PHP links the static C runtime, so `crtenv_windows.go` sets PHP's environment through cgo.
- The release job caches the buildroot and the header folders of PHP's source, under a key that hashes every input of the compile. `build.ps1` writes a marker when the compile ends, and the job saves the cache once the marker exists.
- The job also caches the checkouts of `branch-pins.txt`, under a key of the pin list and its two scripts. SourceForge, which serves pthreads4w, has served a web page in place of a download. The job fetches the checkouts only when both caches miss. It saves them before the compile, which builds them in place.
- `.gitattributes` keeps shell scripts, patches and `branch-pins.txt` as LF, and `fetch-commit.sh` turns `core.autocrlf` off in each checkout.

### Trust store and php.ini

- `runtime/cacert.pem` ships beside the runtime. It is curl's published bundle, and the upstream run moves it to each new extract.
- The launcher sets `DRUPACK_CA_FILE` for every child. It yields to a caller who set it, so a reader behind a TLS-inspection proxy points at their own bundle.

## Reason

One file removes the drift between three builders. Letting `build-static.sh` derive the set from the embedded application ships a build without `pdo_mysql`, `pdo_pgsql`, `opcache` and `argon2`. The list is hand-written because the lock never declares those four.

The platform facts sit in one Python file. The Linux builder containers have bash and no Python, and the Windows build host has no guaranteed Python. The conformance suite checks the finished executable on every Windows leg, so it holds the facts. `build.ps1` repeats the three names static-php-cli does not build on Windows, since it must hand over a list that tool accepts.

PHP's own Windows build rebuilt its libraries with each PHP release, about every four weeks, and tied a PHP minor move to a prebuilt DLL per extension. A compile on the runner gives Windows the library releases the other platforms carry. ICU stays a prebuilt archive: static-php-cli has no Windows recipe for it.

The pinned spc commit makes a rebuild compile with the same tool. The static-php-cli v3 branch has no tagged release and publishes an unchecked nightly, so a commit pin with a hash check is the available control.

Bundling the CA file lets a release reach updates.drupal.org on a host with no CA package, and on Windows, where curl reads no system path. A published release keeps the bundle it shipped with.

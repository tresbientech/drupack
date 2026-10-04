#!/usr/bin/env bash
set -euo pipefail

# The full QA a release tag waits on. It builds the job image, which compiles both
# runtimes from this checkout. It builds Drupacked Demo, and a copy of it asking
# for Node, with drupack-build inside
# that image, where no Docker daemon answers, and the engine executable beside
# it. It then runs the cases that need a daemon on this host, and the engine
# executable's cases against the Drupacked Demo application.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
platform=linux-$(go env GOARCH)
executable=dist/drupacked-demo-$platform
engine=dist/engine/drupack-$platform

# The work directories and test results hold millions of small files per run.
# They live outside the checkout, one root per checkout, mounted at the same
# path in the job container. dist/work links to the site's work directory for
# dev-server.sh and the vendor link below; -T fails on a real dist/work.
work=${DRUPACK_QA_WORK:-/tmp/drupack-qa/$(basename -- "$PWD")}
mkdir -p dist "$work/work" "$work/node-work" "$work/engine-work" "$work/results"
ln -sfnT "$work/work" dist/work

# The job image carries both of this architecture's runtimes, so the build names
# none. The chain tests the glibc file alone, so it packs no musl one.
docker build --target job -t drupack-job .
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/src" -v "$work:$work" -w /src drupack-job \
    drupack-build --site examples/drupacked-demo --platform "$platform" --libc glibc \
    --output dist --work "$work/work"
# The Node fixture's build tests its glibc file, and the musl file's refusal runs below.
node_site=$(mktemp -d)
trap 'rm -rf "$node_site"' EXIT
bash build/node-fixture.sh "$node_site"
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/src" -v "$work:$work" -v "$node_site:/node-site" -w /src drupack-job \
    drupack-build --site /node-site --platform "$platform" --libc both \
    --output dist/node --work "$work/node-work"
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/src" -v "$work:$work" -w /src drupack-job \
    drupack-build --engine-executable --platform "$platform" --libc glibc --output dist/engine --work "$work/engine-work"

# The PHP unit files read the vendor directory of the site just built.
ln -sfn ../dist/work/app/vendor application/vendor
"$executable" php-cli "$PWD/application/tests/launch_test.php"
"$executable" php-cli "$PWD/application/tests/site_data_test.php"
"$executable" php-cli "$PWD/application/tests/serve_test.php"
"$executable" php-cli "$PWD/application/tests/drupack_install_test.php"
"$executable" php-cli "$PWD/application/tests/windows_paths_test.php"
"$executable" php-cli "$PWD/application/tests/site_data_public_stream_test.php"
(cd launcher && go test ./...)
(cd runtime/watch && go test ./...)
python3 -m unittest discover -s tests/conformance -p test_harness.py
python3 -m unittest discover -s tests/conformance -p test_environment.py
python3 -m unittest discover -s tests/conformance -p test_app_payload.py
python3 -m unittest discover -s tests/upstream
DRUPACK_TEST_ENGINE=$engine python3 tests/conformance "$executable" "$work/results/conformance" --site-tests examples/drupacked-demo/tests \
    -k OfflineRun -k NetworkListener -k ServerDatabase -k PostgresqlLifecycle -k CacheRootFull \
    -k ExistingSiteAdoption -k EngineExecutable
python3 tests/conformance "dist/node/node-demo-$platform-musl" "$work/results/node-musl" -k NodeRefusals

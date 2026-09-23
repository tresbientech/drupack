#!/usr/bin/env bash
set -euo pipefail

# The full QA a release tag waits on. It builds the job image, which compiles both
# runtimes from this checkout. It builds Mercury Demo with drupack-build inside
# that image, where no Docker daemon answers, then runs the cases that need a
# daemon on this host.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
platform=linux-$(go env GOARCH)
executable=dist/drupack-$platform

# The job image carries both of this architecture's runtimes, so the build names none.
docker build --target job -t drupack-job .
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/src" -w /src drupack-job \
    drupack-build --site examples/mercury-demo --platform "$platform" --libc both \
    --output dist --work dist/work

# The PHP unit files read the vendor directory of the site just built.
ln -sfn ../dist/work/app/vendor application/vendor
"$executable" php-cli "$PWD/application/tests/launch_test.php"
(cd launcher && go test ./...)
python3 -m unittest discover -s tests/conformance -p test_harness.py
python3 tests/conformance "$executable" test-results/conformance --site-tests examples/mercury-demo/tests \
    -k OfflineRun -k NetworkListener -k ServerDatabase -k PostgresqlLifecycle -k CacheRootFull

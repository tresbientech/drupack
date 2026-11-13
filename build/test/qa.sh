#!/usr/bin/env bash
set -euo pipefail

# The full QA, which release.yml's qa jobs run on a release candidate, one phase per job.
# Every phase builds the job image (both runtimes) and builds its sites inside it.
#   demo     the checks that need no built file, Drupacked Demo at the build/release/demo-pin.sh
#            commit with the first half of its build's suite, and the PHP unit files
#   variant  a copy with a Mercure hub, and its musl file's cases
#   host     Drupacked Demo with the second half of its build's suite, a copy without
#            Node, the engine executable, the cases that need a daemon on this host, and
#            the engine executable's cases against the demo
# No phase runs all three on one build of each site, whose build runs its whole suite.
# Usage: build/test/qa.sh [demo|variant|host]
phase=${1:-}
case $phase in
    '' | demo | variant | host) ;;
    *) echo 'Usage: build/test/qa.sh [demo|variant|host]' >&2; exit 2 ;;
esac
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
platform=linux-$(go env GOARCH)
executable=dist/drupacked-demo-$platform
engine=dist/engine/drupack-$platform

# The work directories and test results hold millions of small files per run.
# They live outside the checkout, one root per checkout, mounted at the same
# path in the job container.
work=${DRUPACK_QA_WORK:-/tmp/drupack-qa/$(basename -- "$PWD")}
mkdir -p dist "$work/work" "$work/variant-work" "$work/engine-work" "$work/results"

# In a git worktree, .git is a file that names a directory under the main
# checkout's .git. The job container gets that .git at the same path.
git_directory=$(git rev-parse --path-format=absolute --git-common-dir)
checkout=(-v "$PWD:/src" -v "$git_directory:$git_directory")

. build/release/demo-pin.sh
demo=$work/demo
git init -q "$demo"
git -C "$demo" fetch -q --depth 1 "https://github.com/$demo_repository" "$demo_ref"
git -C "$demo" checkout -q --detach FETCH_HEAD

# The job image carries both of this architecture's runtimes, so the build names
# none. The chain tests the glibc file alone, so it packs no musl one.
# DRUPACK_QA_RUNTIMES names a folder of built runtimes, one linux-ARCH-LIBC folder
# each, which carry DRUPACK_VERSION. Without it the builder images build both.
job_arguments=()
if [ -n "${DRUPACK_QA_RUNTIMES:-}" ]; then
    job_arguments=(--build-arg RUNTIMES=release-runtimes --build-context "release-runtimes=$DRUPACK_QA_RUNTIMES"
        --build-arg "DRUPACK_VERSION=$DRUPACK_VERSION")
fi
docker build --target job "${job_arguments[@]}" -t drupack-job .

# A build's own test results take about 30 GB. The whole chain deletes them, since
# the runner's disk cannot hold two builds' results and the later steps' own. One
# phase keeps them: the deletion takes 6 to 10 minutes.
discard_results() {
    [ -n "$phase" ] || rm -rf "$work/$1/test-results"
}

unit_checks() {
    bash build/test/go-tests.sh
    bash runtime/frankenphp/adapt-test.sh gnu
    bash build/test/unit-tests.sh
}

# Builds Drupacked Demo. The build runs the share of its suite that $1 names as
# DRUPACK_SHARD, and the whole suite without one.
build_demo() {
    docker run --rm --user "$(id -u):$(id -g)" -e "DRUPACK_SHARD=${1:-}" "${checkout[@]}" -v "$work:$work" -w /src drupack-job \
        drupack-build --site "$demo" --platform "$platform" --libc glibc \
        --output dist --work "$work/work"
    discard_results work
}

php_unit_files() {
    # The PHP unit files read the vendor directory of their own Composer install.
    docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -e DRUPACK_CA_FILE=/opt/drupack/engine/runtime/cacert.pem \
        "${checkout[@]}" -w /src drupack-job sh -c 'PHPRC=$(dirname "$DRUPACK_PHP") "$DRUPACK_PHP" php-cli "$DRUPACK_COMPOSER" \
            install --working-dir=application/tests --no-interaction --prefer-dist --no-plugins'
    # Every file gets the arguments a and b, which argv_test.php checks.
    for file in application/tests/*_test.php; do
        "$executable" php-cli "$PWD/$file" a b
    done
}

# The variant fixture's build tests its glibc file. Its musl file's hub cases run after it.
variant() {
    variant_site=$(mktemp -d)
    trap 'rm -rf "$variant_site"' EXIT
    bash build/test/variant-fixture.sh "$demo" "$variant_site"
    docker run --rm --user "$(id -u):$(id -g)" "${checkout[@]}" -v "$work:$work" -v "$variant_site:/variant-site" -w /src drupack-job \
        drupack-build --site /variant-site --platform "$platform" --libc both \
        --output dist/variant --work "$work/variant-work"
    discard_results variant-work
    python3 tests/conformance "dist/variant/variant-demo-$platform-musl" "$work/results/variant-musl" -k MercureHub -k ProjectRules
}

# Reads the demo build's executable, site.json and payload.
host_cases() {
    docker run --rm --user "$(id -u):$(id -g)" "${checkout[@]}" -v "$work:$work" -w /src drupack-job \
        drupack-build --engine-executable --platform "$platform" --libc glibc --output dist/engine --work "$work/engine-work"

    # Drupacked Demo again without Node and without the static key, for the cases of a site
    # that carries neither. operate.php reads the site.json inside the payload, so the copy swaps
    # that file too, and its checksum names an application cache entry of its own.
    no_node=$work/no-node
    mkdir -p dist/no-node "$no_node"
    python3 -c 'import json, sys
with open(sys.argv[1]) as handle:
    site = json.load(handle)
del site["node"]
del site["static"]
with open(sys.argv[2], "w") as handle:
    json.dump(site, handle, indent=2)' dist/site.json "$no_node/site.json"
    cp "$no_node/site.json" dist/no-node/site.json
    cp "$work/work/payload/app-payload.tar" "$no_node/app-payload.tar"
    tar --delete -f "$no_node/app-payload.tar" ./site.json
    tar --mtime=@0 --owner=0 --group=0 --numeric-owner --mode=u+rw,go+rX -rf "$no_node/app-payload.tar" \
        -C "$no_node" ./site.json
    sha256sum "$no_node/app-payload.tar" | cut -d ' ' -f 1 | tr -d '\n' >"$no_node/app_checksum.txt"
    zstd -q -19 -f --rm "$no_node/app-payload.tar" -o "$no_node/app-payload.tar.zst"
    docker run --rm --user "$(id -u):$(id -g)" "${checkout[@]}" -v "$work:$work" -w /src/launcher -e CGO_ENABLED=0 drupack-job \
        sh -c 'go run ./cmd/pack -target "$1" -runtime "/opt/drupack/runtimes/$1-glibc" -payload "$2" \
            -version "$DRUPACK_ENGINE_VERSION" -site-version dev -output "../dist/no-node/drupacked-demo-$1"' sh "$platform" "$no_node"

    DRUPACK_TEST_ENGINE=$engine python3 tests/conformance "$executable" "$work/results/conformance" --site-tests "$demo/tests" \
        -k OfflineRun -k NetworkListener -k ServerDatabase -k PostgresqlLifecycle -k CacheRootFull \
        -k ExistingSiteAdoption -k EngineExecutable -k TlsSources -k TrustCommand -k StaticSite
    python3 tests/conformance "dist/no-node/drupacked-demo-$platform" "$work/results/no-node" -k StaticSite
}

case $phase in
    demo)
        unit_checks
        build_demo 1/2+extra
        php_unit_files
        ;;
    variant) variant ;;
    host)
        build_demo 2/2+extra
        host_cases
        ;;
    '')
        # The checks that need no built file run before any executable builds.
        unit_checks
        build_demo
        variant
        php_unit_files
        host_cases
        ;;
esac

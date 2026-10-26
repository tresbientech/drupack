#!/usr/bin/env bash
set -euo pipefail

# The full QA, which release.yml's qa job runs on a release candidate. It builds the job
# image (both runtimes), then inside it Drupacked Demo at the build/demo-pin.sh commit, a
# copy with a Mercure hub, a copy without Node, and the engine executable. It then runs the
# cases that need a daemon on this host, and the engine executable's cases against the demo.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
platform=linux-$(go env GOARCH)
executable=dist/drupacked-demo-$platform
engine=dist/engine/drupack-$platform

# The work directories and test results hold millions of small files per run.
# They live outside the checkout, one root per checkout, mounted at the same
# path in the job container. dist/work links to the site's work directory for
# dev-server.sh and the vendor link below; -T fails on a real dist/work.
work=${DRUPACK_QA_WORK:-/tmp/drupack-qa/$(basename -- "$PWD")}
mkdir -p dist "$work/work" "$work/variant-work" "$work/engine-work" "$work/results"
ln -sfnT "$work/work" dist/work

. build/demo-pin.sh
demo=$work/demo
git init -q "$demo"
git -C "$demo" fetch -q --depth 1 "https://github.com/$demo_repository" "$demo_ref"
git -C "$demo" checkout -q --detach FETCH_HEAD

# The job image carries both of this architecture's runtimes, so the build names
# none. The chain tests the glibc file alone, so it packs no musl one.
docker build --target job -t drupack-job .
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/src" -v "$work:$work" -w /src drupack-job \
    drupack-build --site "$demo" --platform "$platform" --libc glibc \
    --output dist --work "$work/work"
# The variant fixture's build tests its glibc file. Its musl file's Node refusal and hub cases run below.
variant_site=$(mktemp -d)
trap 'rm -rf "$variant_site"' EXIT
bash build/variant-fixture.sh "$demo" "$variant_site"
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/src" -v "$work:$work" -v "$variant_site:/variant-site" -w /src drupack-job \
    drupack-build --site /variant-site --platform "$platform" --libc both \
    --output dist/variant --work "$work/variant-work"
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/src" -v "$work:$work" -w /src drupack-job \
    drupack-build --engine-executable --platform "$platform" --libc glibc --output dist/engine --work "$work/engine-work"

# Drupacked Demo again without Node, for the case of a site that carries none.
# launch.php reads the site.json inside the payload, so the copy swaps that file
# too, and its checksum names an application cache entry of its own.
no_node=$work/no-node
mkdir -p dist/no-node "$no_node"
python3 -c 'import json, sys
with open(sys.argv[1]) as handle:
    site = json.load(handle)
del site["node"]
with open(sys.argv[2], "w") as handle:
    json.dump(site, handle, indent=2)' dist/site.json dist/no-node/site.json
cp "$work/work/payload/app-payload.tar" "$no_node/app-payload.tar"
tar --delete -f "$no_node/app-payload.tar" ./site.json
tar --mtime=@0 --owner=0 --group=0 --numeric-owner --mode=u+rw,go+rX -rf "$no_node/app-payload.tar" \
    -C dist/no-node ./site.json
sha256sum "$no_node/app-payload.tar" | cut -d ' ' -f 1 | tr -d '\n' >"$no_node/app_checksum.txt"
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/src" -v "$work:$work" -w /src/launcher -e CGO_ENABLED=0 drupack-job \
    sh -c 'go run ./cmd/pack -runtime "/opt/drupack/runtimes/$1-glibc" -entry drupack -version "$DRUPACK_ENGINE_VERSION" \
        -source . -output "../dist/no-node/drupacked-demo-$1" -app "$2/app-payload.tar" -app-checksum "$2/app_checksum.txt" \
        -site ../dist/no-node/site.json -site-version dev' sh "$platform" "$no_node"

# The PHP unit files read the vendor directory of the site just built.
ln -sfn ../dist/work/app/vendor application/vendor
# Every file gets the arguments a and b, which argv_test.php checks.
for file in application/tests/*_test.php; do
    "$executable" php-cli "$PWD/$file" a b
done
bash build/go-tests.sh
bash build/unit-tests.sh
DRUPACK_TEST_ENGINE=$engine python3 tests/conformance "$executable" "$work/results/conformance" --site-tests "$demo/tests" \
    -k OfflineRun -k NetworkListener -k ServerDatabase -k PostgresqlLifecycle -k CacheRootFull \
    -k ExistingSiteAdoption -k EngineExecutable -k TlsSources -k TrustCommand
python3 tests/conformance "dist/variant/variant-demo-$platform-musl" "$work/results/variant-musl" -k NodeRefusals -k MercureHub -k ProjectRules
python3 tests/conformance "dist/no-node/drupacked-demo-$platform" "$work/results/no-node" -k NodeRefusals

#!/usr/bin/env bash
set -euo pipefail

usage='Usage: create-manifest.sh ARTIFACT VERSION URL OUTPUT PLATFORM ARCHITECTURE'
artifact=${1:?$usage}
version=${2:?$usage}
url=${3:?$usage}
output=${4:?$usage}
platform=${5:?$usage}
architecture=${6:?$usage}

artifact_name=$(basename "$artifact")
if command -v sha256sum >/dev/null; then
  sha256=$(sha256sum "$artifact" | cut -d ' ' -f 1)
else
  sha256=$(shasum -a 256 "$artifact" | cut -d ' ' -f 1)
fi
if stat --format=%s "$artifact" >/dev/null 2>&1; then
  size=$(stat --format=%s "$artifact")
else
  size=$(stat -f %z "$artifact")
fi
commit=$(git rev-parse HEAD)
mkdir -p "$(dirname "$output")"

printf '{\n  "artifact": "%s",\n  "platform": "%s",\n  "architecture": "%s",\n  "version": "%s",\n  "url": "%s",\n  "sha256": "%s",\n  "size": %s,\n  "build_commit": "%s"\n}\n' \
  "$artifact_name" "$platform" "$architecture" "$version" "$url" "$sha256" "$size" "$commit" >"$output"

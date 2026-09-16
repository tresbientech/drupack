#!/usr/bin/env bash
set -euo pipefail

usage='Usage: package-artifact.sh SOURCE VERSION BASE_URL OUTPUT PLATFORM ARCHITECTURE'
source_artifact=${1:?$usage}
version=${2:?$usage}
base_url=${3:?$usage}
output_directory=${4:?$usage}
platform=${5:?$usage}
architecture=${6:?$usage}
script_directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)

# The version comes from a workflow_dispatch input.
case "$version" in
  *[!A-Za-z0-9._-]*|'')
    printf 'Release version contains unsupported characters: %s\n' "$version" >&2
    exit 1
    ;;
esac

artifact_name="drupack-${version}-${platform}-${architecture}"
mkdir -p "$output_directory"
artifact="$output_directory/$artifact_name"
cp "$source_artifact" "$artifact"
chmod 0755 "$artifact"
if command -v sha256sum >/dev/null; then
  (cd "$output_directory" && sha256sum "$artifact_name" >checksums.txt)
else
  (cd "$output_directory" && shasum -a 256 "$artifact_name" >checksums.txt)
fi
"$script_directory/create-manifest.sh" "$artifact" "$version" "$base_url/$artifact_name" "$output_directory/release.json" "$platform" "$architecture"

#!/bin/sh
# Installs @NAME@ @VERSION@ into the current directory. Run it with:
#   curl -fsSL @BASE_URL@/install-@NAME@.sh | sh
set -eu

name='@NAME@'
version='@VERSION@'
base_url='@BASE_URL@'
# Each build this release published, with its SHA-256.
builds='
@BUILDS@
'

fail() {
    echo "$*" >&2
    exit 1
}

case "$(uname -s)" in
    Linux) os=linux ;;
    Darwin) os=macos ;;
    *) fail "This script installs on Linux and macOS. On Windows, run install-$name.ps1 in PowerShell." ;;
esac
case "$(uname -m)" in
    x86_64 | amd64) arch=amd64 ;;
    aarch64 | arm64) arch=arm64 ;;
    *) fail "$name has no build for a $(uname -m) processor." ;;
esac
# A Terminal running under Rosetta reports x86_64 on an Apple silicon Mac.
if [ "$os" = macos ] && [ "$(sysctl -n sysctl.proc_translated 2>/dev/null)" = 1 ]; then
    arch=arm64
fi
target=$os-$arch

expected=$(echo "$builds" | awk -v target="$target" '$1 == target { print $2 }')
if [ -z "$expected" ]; then
    fail "$name $version has no $target build. It has:$(echo "$builds" | awk 'NF { printf " %s", $1 }')."
fi

url=$base_url/$name-$version-$target
# mktemp creates a new file, so a file or link already in this directory cannot
# stand in for the download.
download=$(mktemp "./.$name.XXXXXX")
trap 'rm -f "$download"' EXIT
echo "Downloading $url"
if command -v curl >/dev/null; then
    curl -fsSL -o "$download" "$url"
else
    wget -q -O "$download" "$url"
fi

if command -v sha256sum >/dev/null; then
    actual=$(sha256sum "$download" | cut -d ' ' -f 1)
else
    actual=$(shasum -a 256 "$download" | cut -d ' ' -f 1)
fi
if [ "$actual" != "$expected" ]; then
    fail "The download's SHA-256 is $actual, and the release lists $expected. Nothing was installed."
fi

if [ -e "$name" ]; then
    echo "Replacing ./$name"
fi
chmod 755 "$download"
mv -f "$download" "$name"
echo "Installed $name $version in $PWD/$name. Start it with: ./$name"
echo "To run it from any directory, move it onto your PATH:"
echo "  mkdir -p ~/.local/bin && mv $name ~/.local/bin/"
case ":$PATH:" in
    *":$HOME/.local/bin:"*) ;;
    *) echo "  and add ~/.local/bin to PATH in your shell profile: export PATH=\"\$HOME/.local/bin:\$PATH\"" ;;
esac

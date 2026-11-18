#!/bin/sh
# Usage: runtime-libraries.sh BUILDROOT FLAGS
# Prints "RECORD VERSION" for each pkg-config record in BUILDROOT whose library is on the link
# line FLAGS. A record's first -l names its own library, and the others are its dependencies.
# Exits 1 when no record matches, which a wrong path or an unread link line produces.
set -eu

# A link line names a library as -lNAME or as a path ending in libNAME.a.
linked=$(printf '%s\n' "$2" | tr ' ' '\n' | sed -n 's/^-l//p; s#^.*/lib\(.*\)\.a$#\1#p')
found=
for record in "$1"/lib/pkgconfig/*.pc; do
    version=$(sed -n 's/^Version: *//p' "$record" | head -n 1)
    # ncurses keeps its release in a variable.
    if [ "$version" = '${version}' ]; then
        version=$(sed -n 's/^version=//p' "$record" | head -n 1)
    fi
    library=$(sed -n 's/^Libs: *//p' "$record" | tr ' ' '\n' | sed -n 's/^-l//p' | head -n 1)
    # libargon2 holds a word in place of a release.
    case $version in
        [0-9]*)
            if printf '%s\n' "$linked" | grep -Fqx -- "$library"; then
                printf '%s %s\n' "$(basename "$record" .pc)" "$version"
                found=yes
            fi
            ;;
    esac
done
if [ -z "$found" ]; then
    echo "No pkg-config record in $1 names a library on the link line." >&2
    exit 1
fi

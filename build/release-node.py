#!/usr/bin/env python3
"""The drupal.org release node of a version tag. Packagist takes each
drupal/drupack version from one, and drupal.org has no API that creates it.

    python3 build/release-node.py            stops while Packagist lists no release for the highest tag
    python3 build/release-node.py TAG PHAR   prints what the release form takes for TAG, as Markdown

PHAR is drupalorg-cli, which writes the notes from the commits since the tag before.
"""

import json
import subprocess
import sys

from upstream import PACKAGIST_METADATA, fetch, release_key

PACKAGE = "drupal/drupack"
# 3624016 is the node of the drupal.org project.
FORM = "https://www.drupal.org/node/add/project-release/3624016"
# The headings of drupalorg-cli's notes, and the release type each one stands for on the form.
RELEASE_TYPES = {"Bug": "Bug fixes", "Feature": "New features"}


def unlisted(tags, listed):
    highest = max(tags, key=release_key)
    return None if highest in listed else highest


def previous(tags, tag):
    return max((other for other in tags if release_key(other) < release_key(tag)), key=release_key)


def form_entry(tag, notes):
    types = [name for heading, name in RELEASE_TYPES.items() if f"<h4>{heading}</h4>" in notes]
    return "\n".join([
        f"### drupal.org release for {tag}",
        "",
        f"1. Open the [release form]({FORM}) and pick the tag `{tag}`.",
        f"2. Release type: {', '.join(types) or 'none'}.",
        "3. Write the short description, and put it in place of the first line of the notes.",
        "4. Paste the notes.",
        "",
        "```html",
        notes.strip(),
        "```",
    ])


def main():
    tags = subprocess.run(["git", "tag", "--list"], check=True, capture_output=True, text=True).stdout.split()
    if len(sys.argv) == 3:
        tag, phar = sys.argv[1:]
        notes = subprocess.run(["php", phar, "mrn", previous(tags, tag), tag, "--format=html"],
                               check=True, capture_output=True, text=True).stdout
        print(form_entry(tag, notes))
        return
    entries = json.loads(fetch(f"{PACKAGIST_METADATA}{PACKAGE}.json"))["packages"][PACKAGE]
    tag = unlisted(tags, [entry["version"] for entry in entries])
    if tag is not None:
        sys.exit(f"Packagist lists no {PACKAGE} {tag}. Create its release at {FORM}. "
                 f"The notes job of the Upstream run that tagged {tag} holds the text to paste.")


if __name__ == "__main__":
    main()

#!/usr/bin/env bash

# The Drupacked Demo commit every engine build packs and tests. The forge's
# upstream job moves demo_ref forward to the demo's latest release, and holds a
# commit that release does not descend from. demo_release names the release at
# demo_ref, and stays empty for a commit set by hand.

demo_repository=tresbientech/drupacked-demo
demo_release=2026.10.11
demo_ref=56da4e9b0e5aaade175a506038c24a3300af40bf

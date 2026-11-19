#!/usr/bin/env bash

# The Drupacked Demo commit every engine build packs and tests. The
# upstream job moves demo_ref forward to the demo's latest release, and holds a
# commit that release does not descend from. demo_release names the release at
# demo_ref, and stays empty for a commit set by hand.

demo_repository=tresbientech/drupacked-demo
demo_release=2026.11.9
demo_ref=2beb575395357b10dc3cc631a79989de489f55e6

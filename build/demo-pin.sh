#!/usr/bin/env bash

# The Drupacked Demo commit every engine build packs and tests. The forge's
# upstream job moves demo_ref forward to the demo's latest release, and holds a
# commit that release does not descend from. demo_release names the release at
# demo_ref, and stays empty for a commit set by hand.

demo_repository=tresbientech/drupacked-demo
demo_release=2026.10.14
demo_ref=6a8223f4ac7ab74a3f857a1dcd40c31c5c2d2972

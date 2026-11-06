#!/usr/bin/env bash

# The Drupacked Demo commit every engine build packs and tests. The forge's
# upstream job moves demo_ref forward to the demo's latest release, and holds a
# commit that release does not descend from. demo_release names the release at
# demo_ref, and stays empty for a commit set by hand.

demo_repository=tresbientech/drupacked-demo
demo_release=2026.11.2
demo_ref=71d1575c7351097ac8995884a6f56aeb24c8a139

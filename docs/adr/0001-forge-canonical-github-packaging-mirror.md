# The Forge is canonical and GitHub is a packaging mirror

Drupack's canonical repository is `tresbientech/drupack` on git.tresbien.tech, the Forge. The GitHub repository `tresbientech/drupack` is a mirror that publishes release executables. The Forge requires sign-in for every repository page, so it cannot serve public downloads.

## Decision

- Only `main` and version tags are pushed, and only to the Forge.
- A Forge Actions job copies branches and tags to GitHub on every push. It authenticates with an SSH deploy key stored as a Forge repository secret.
- GitHub carries the full history, with the same commit SHAs as the Forge.
- A version tag such as `0.1.0` starts a GitHub Actions workflow. It tests the release executables and publishes them as a GitHub Release.
- GitHub takes no issues or pull requests.
- Merges happen on the Forge. The next sync overwrites a merge made on GitHub.

## Considered options

- A filtered subset, as `DRUPAL_DATA_STACK/deploy/drupatch/publish.sh` publishes. This repository is standalone and its history holds no private files. A subset would give GitHub different SHAs.
- Gitea's built-in push mirror. Gitea 1.27 authenticates push mirrors over HTTPS only, so it needs a personal access token. The token expires, belongs to a personal account, and fails without notice.
- Releases on the Forge. `REQUIRE_SIGNIN_VIEW = true` blocks anonymous downloads.

## Consequences

- A failed sync is a failed run in the Forge Actions tab. Syncing pauses while the Build host is down.
- The GitHub repository became public on 2026-09-17. Its Releases download without sign-in.
- The mirror job can also push to a drupal.org project. Its merge requests would have to land through the Forge, because the mirror overwrites merges made on drupal.org.

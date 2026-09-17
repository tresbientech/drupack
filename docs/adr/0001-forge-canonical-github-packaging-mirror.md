# The Forge is canonical, with GitHub and drupal.org mirrors

Drupack's canonical repository is `tresbientech/drupack` on git.tresbien.tech, the Forge. The GitHub repository `tresbientech/drupack` is a mirror that publishes release executables. The drupal.org general project `drupack` is a second mirror. The Forge requires sign-in for every repository page, so it cannot serve public downloads.

## Decision

- Only `main` and version tags are pushed, and only to the Forge.
- A Forge Actions job runs when a version tag reaches the Forge, or on a manual dispatch. It clones the Forge repository, then pushes its branches and tags to each mirror.
- The GitHub push authenticates with an SSH deploy key stored as a Forge repository secret. It forces and prunes.
- The drupal.org push authenticates over HTTPS with a GitLab project access token, stored as the `DRUPAL_ORG_MIRROR_TOKEN` Forge repository secret. The token has the Maintainer role and the `write_repository` scope only.
- The drupal.org push never forces or prunes.
- Both mirrors carry the full history, with the same commit SHAs as the Forge.
- A version tag such as `0.1.0` starts a GitHub Actions workflow. It tests the release executables and publishes them as a GitHub Release.
- GitHub takes no issues or pull requests.
- Merges happen on the Forge.

## Considered options

- A filtered subset, as `DRUPAL_DATA_STACK/deploy/drupatch/publish.sh` publishes. This repository is standalone and its history holds no private files. A subset would give the mirrors different SHAs.
- Gitea's built-in push mirror. Gitea 1.27 authenticates push mirrors over HTTPS only, so GitHub would need a personal access token. The token expires, belongs to a personal account, and fails without notice.
- The maintainer's drupal.org SSH key. A drupal.org SSH key belongs to an account, so the Forge secret could push to every project that account can write to.
- Releases on the Forge. `REQUIRE_SIGNIN_VIEW = true` blocks anonymous downloads.

## Consequences

- A failed sync is a failed run in the Forge Actions tab. Syncing pauses while the Build host is down.
- Commits pushed between version tags stay on the Forge until the next tag or a manual dispatch.
- A manual `release.yml` dispatch builds the commit GitHub last received.
- A failed GitHub push still runs the drupal.org push.
- The next sync overwrites a merge made on GitHub.
- After a merge made on drupal.org, the drupal.org push fails until the Forge holds that commit. drupal.org merge requests land through the Forge.
- A branch or tag deleted on the Forge stays on drupal.org.
- The drupal.org push fails once the project access token expires, until the secret holds a new token.
- The GitHub repository became public on 2026-09-17. Its Releases download without sign-in.

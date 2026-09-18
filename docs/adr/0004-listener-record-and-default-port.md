# Drupack records its listener and serves on port 7225

Accepted on 2026-09-18.

## Context

`drupack dr user:login` printed `http://default/user/reset/...`. Drush builds a
URL from `--uri`, and nothing passed one, so Drupal fell back to the site
directory name. Every Drush-generated URL had the same defect.

The host and port live in `--listen` and `--host`, which default to
`127.0.0.1:8080` and `localhost`. A `dr` command that repeats neither has no way
to know its site was started on another port.

Port 8080 also collides with most of what a developer already runs.

## Decision

- Every start writes `listener` in Site data, holding the listen address and the
  permitted host it served on.
- `dr` reads that record as its default. `--listen` and `--host` on the command
  line still win. A start never reads it, so `--listen` does not become sticky.
- `launch.php` exports `DRUSH_OPTIONS_URI`, so every Drush child inherits the
  URI. No call site threads a flag.
- The default port becomes 7225. It spells PACK on a phone keypad, so the
  default names the product.

## Considered options

- Use the options each command was given. One line of code and no new state.
  Anyone serving on another port has to repeat `--listen` on every `dr` command
  or receive a link pointing at nothing.
- Ask the running server for its address. Always current, and `dr` is often used
  with the site stopped.
- Pass `--uri` at each call site. Four paths build Drush commands, so the value
  would live in four places.

## Consequences

- A plain `drupack dr user:login` produces a working link whatever port the site
  runs on.
- Site data written before this carries no record and falls back to the
  defaults. A site that ran on 8080 therefore starts on 7225 after an upgrade.
  Nothing pinned the port before this, so no site loses data.
- A corrupt record fails the start loudly, as `installation-progress` does.
- 7225 sits above 1024, so no start needs root, and below 32768, so it avoids
  the range Linux draws ephemeral ports from.
- `dr --help`, `dr -h` and `dr list` exec before the data directory resolves and
  inherit no URI. They print command lists and generate no site URLs.

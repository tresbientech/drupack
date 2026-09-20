# Dashboard on every start

From [docs/rfc/dashboard-on-every-start.md](../rfc/dashboard-on-every-start.md).

## Problem Statement

A site owner starts Drupack and wants to work on their site. Where the browser lands, and whether they are signed in, depends on which start it is.

A first start that creates the administrator account mints a one-time login link and opens the browser on it. The owner lands signed in, on `/user/1/edit`. A later start mints no link: the owner reaches the front page signed out, and has to find the login form and a password, or know `dr user:login`.

`runtime/launch.php` mints a link only when a start sets the administrator account. `packaging/entrypoint.go` opens whatever target it receives, on a start that has a terminal on standard input, or owns its Windows console, and was not given `--no-browser`. The packaged site already enables `drupal/dashboard`, so `/admin/dashboard` exists on every installed site. Nothing opens it.

## Solution

Every start that opens a browser opens it on a one-time login link whose destination is `/admin/dashboard`. The owner lands signed in, on the dashboard, whichever start it is.

- The link comes from `user:login --no-browser /admin/dashboard`, which Drush returns already carrying `?destination=/admin/dashboard`. Nothing new parses or builds a URL.
- Every start mints one link and prints it, whether or not it opens a browser. A script, a container and a start under `--no-browser` print one too, where today only a first start does.
- The printed link carries the dashboard destination, so an owner who follows it from the terminal lands where the browser would have.
- The link names the administrator account the site was installed with, uid 1, as `dr user:login` already does.
- A site whose dashboard module was uninstalled gets a link to a path that 404s. The owner sees Drupal's own page.

## Decisions the owner took, 2026-09-20

- A start of an already-installed site opens a browser. The `$created` half of today's condition goes, so any start with a terminal, or Windows console ownership, opens one unless `--no-browser` says otherwise.
- Every start prints its link, a first start and every later one. The link is a working credential, so it now appears in whatever captures a start's output, including a container's log.
- The Windows console-owned path carries the same link, and no case proves which URL reached the browser there, since it calls `rundll32` directly. The gap is accepted.
- Two starts of one site each mint a link and each open a browser. Extending the startup lock to a start with no step to run stays its own change.

## User Stories

1. As the site owner, I want every start that opens a browser to land me signed in on the dashboard, so that I never meet a login form.
2. As the site owner, I want my first start's printed link to also land on the dashboard, so that choosing my password does not cost me the page I land on anyway.
3. As the site owner, I want every start to print its link, so that I can reach my dashboard from the terminal when no browser opened.
4. As the site owner, I want to know that a printed link is a working credential, so that I treat a captured log as something to guard.
5. As the site owner, I want a plain restart of my already-running site to also open my browser on the dashboard, so that "every start" means every start.
6. As the maintainer, I want the login command itself to carry the destination, so that no new code parses or builds a URL.
7. As the maintainer, I want the printed link and the browser-opening link to share one mint whenever both apply, so that a start never asks Drush for two links it only needs one of.
8. As the maintainer, I want the conformance suite to drive a real browser-opening start and follow the link it receives, so that a case proves the dashboard landing.
9. As the maintainer, I want the site and initialization cases' assertions of today's `/user/1/edit` landing moved to the dashboard, so that the suite tests what Drupack now ships.
10. As the maintainer, I want the README's first-run story to name the dashboard, so that a new site owner reads an accurate first run.

## Implementation Decisions

### The link

- `loginLink()` takes a destination path. Every mint in `runtime/launch.php` passes `/admin/dashboard`.
- The printed line loses its `credentialsRequired($steps)` gate, so every start prints one. `credentialsRequired()` keeps its other caller, which decides whether a start generates a password.
- The browser-opening link mints only when the browser will open. It reuses the printed link when both apply, so a start mints at most one link.
- Browser eligibility drops the requirement that the start ran an initialization step. It keeps requiring a terminal on standard input, or Windows console ownership, and the absence of `--no-browser`.
- `packaging/entrypoint.go` needs no change. It already opens whatever target and browser flag `launch.php` exports.

### Suite proof

- A new harness capability attaches a start to a pseudoterminal and puts a recorder first on `PATH`, named for the platform's opener: `xdg-open` on Linux, `open` on macOS. The recorder appends the URL it receives to a file.
- A case marked Linux and macOS proves a later start opens the browser on a dashboard link and prints none. Windows calls `rundll32` directly, with no `PATH` command to intercept, so it stays out of that case.
- No wait-table change. The extra Drush call the RFC measured at 0.55s stays inside the existing `start` and `dr` budgets.

## Testing Decisions

A good case here drives the packaged executable the way a site owner does: start it, read its terminal output, and follow the exact URL a real browser would open.

The pseudoterminal attachment and the `PATH` recorder are harness capability, beside the harness's existing process control, not one-off code in a case module. The recorder gets its own `test_harness.py` case that calls it directly with an arbitrary URL and asserts the file it writes, needing no built executable.

The two moved assertions, in `site_cases.py` and `initialization_cases.py`, keep every other check they made. Only the destination changes.

Prior art: `site_cases.py`'s `test_default_port_first_start_without_credentials` already follows a login link with a cookie jar and reads where it lands; the new case extends that pattern to a link a browser receives instead of one the case fetches itself.

## Out of Scope

- Changing what happens when the dashboard module is uninstalled. The RFC accepts the resulting 404.
- Any change to `packaging/entrypoint.go`'s polling or browser-opening mechanics.
- Deciding the startup lock's behavior for a start with no initialization step.
- A harness capability that intercepts `rundll32`, or any other proof of the Windows console-owned path.

## Further Notes

The RFC's first account of the browser-opening condition named a terminal on standard input, or Windows console ownership, and the absence of `--no-browser`. The code carried one more requirement, that the start had run an initialization step, which the owner's second decision dropped. Phase 2 removed it and covered the result on Linux and macOS.

The RFC measured the extra Drush call at 0.55s against a start that already takes seconds. This PRD makes no change to that cost.

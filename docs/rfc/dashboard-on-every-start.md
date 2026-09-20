# Open the dashboard on every start

Proposed on 2026-09-20.

## The question

A reader starts Drupack and wants to work on their site. Where does the browser land, and are they signed in?

Today the answer depends on which start it is. A first start that created the administrator account opens a one-time login link, so the reader lands signed in on `/user/1/edit`. A later start from a terminal opens no browser at all: the reader goes to the URL themselves, lands signed out, and needs the password they chose or a `dr user:login` they have to know about.

## What happens today

- `runtime/launch.php` mints a link only when a start sets the administrator account: `credentialsRequired($steps) ? loginLink($binary, $url) : null`.
- `loginLink()` runs `dr user:login --no-browser` and refuses a link that does not start with the site's own URL.
- `openWhenServing()` hands the Go entrypoint the URL to poll and the target to open. The target is the link on a first start, the plain URL otherwise.
- `packaging/entrypoint.go` polls the URL, prints `Drupal is ready.`, then opens the target with `xdg-open`, `open` or `rundll32`.
- A browser opens only when a start both ran an installation step and has a terminal on standard input: `$interactive = $created && stream_isatty(STDIN)`. A later start of an installed site opens none.
- A Windows start that owns its console opens a browser whatever `$created` says, since a file manager has no other way to reach its reader. It opens the front page.
- `--no-browser` suppresses the browser. A script and a container have no terminal, so they open nothing.
- The packaged site enables `drupal/dashboard` 2.2, so `/admin/dashboard` exists on every installed site.

## Decision

1. Every start that opens a browser opens it on a one-time login link whose destination is `/admin/dashboard`. The reader lands signed in, on the dashboard, on a first start and on every later one.
2. A start opens a browser whenever it has a terminal, or owns its Windows console, and `--no-browser` says nothing. The `$created` half of today's condition goes, since it keeps a later start from opening anything.
3. The link comes from the same Drush command with a path: `user:login --no-browser /admin/dashboard`, which returns a link carrying `?destination=/admin/dashboard`. Nothing new parses or builds a URL.
4. Every start mints one link and prints it in the readiness block, whether or not it opens a browser. A reader who lost the browser window, or who runs with `--no-browser`, still has a way in from the terminal.
5. The link names the administrator account the site was installed with, which is uid 1, as `dr user:login` already does.

## Consequences

- Every start now runs one Drush command before it serves, including a start in a container and a start under `--no-browser`. Measured on this machine against an installed SQLite site: 0.55 s, against a start that already takes seconds.
- A reader who starts twice gets two links. Each is single use and expires on its own schedule.
- The printed link is a working credential, so it reaches whatever captures the terminal: a log file, a CI job's output, a scrollback. A first start already prints one there today; this puts one in every start's output.
- The README's first-run story shortens: start the executable, land on the dashboard.
- The conformance suite's site cases assert today's behaviour, that the printed link starts with `/user/reset/1/` and lands on `/user/1/edit`. They move with this change.
- A case must prove that a later start opens the browser on a dashboard link. A start with no terminal opens nothing, so the case gives the start a pseudo-terminal and puts a recorder named `xdg-open` first on `PATH`, which writes the URL it was handed. The case then follows that URL and asserts the landing page. Windows calls `rundll32` directly, so the case stays on Linux and macOS.
- A site whose dashboard module was uninstalled gets a link to a path that 404s. The start does not check the route, and the reader sees Drupal's own page.

## Considered options

- Open `/admin/dashboard` with no login link. The reader lands on the login form, since the dashboard needs a session. That is the behaviour this proposal removes.
- Keep the link to a first start, and open the front page later. Today's behaviour, and the reason a returning reader has to find the login form.
- Mint the link after the site answers, from the Go entrypoint. It keeps the 0.55 s off the start path, at the cost of a second place that runs Drush and handles a credential. The measurement does not justify it.
- Print the link only when a browser opens. It keeps a credential out of a container's log, and it leaves a reader whose browser never appeared with no way in. Decision 4 takes the other side, and the consequences record what that costs.
- Send the reader to `/admin/content` or the front page while signed in. The dashboard is the page Drupal CMS builds for this moment, and the reader can navigate from it.

## Open question

A reader who runs two starts against one site, in two terminals, gets two links and one dashboard. Nothing here decides whether the second start should refuse, which the startup lock already handles, or open a browser at all.

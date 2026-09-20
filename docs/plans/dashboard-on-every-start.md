# Plan: Dashboard on every start

> Source PRD: [dashboard-on-every-start-prd.md](./dashboard-on-every-start-prd.md)
> Source RFC: [dashboard-on-every-start.md](./dashboard-on-every-start.md)

## Architectural decisions

These hold across every phase.

### The link

- `loginLink()` takes a destination path. Every mint in `runtime/launch.php` passes `/admin/dashboard`. Drush's `user:login --no-browser PATH` already returns a link carrying `?destination=PATH`, so nothing new parses or builds a URL.
- The printed line loses its `credentialsRequired($steps)` gate, so it appears on every start.
- Every start mints one link and prints it. The same link opens the browser when a browser opens, so a start mints one link, never two.
- Browser eligibility drops the requirement that the start ran an initialization step. It keeps requiring a terminal on standard input, or Windows console ownership, and the absence of `--no-browser`. This follows the PRD's second owner decision; the plan proceeds on it and flags any reversal as a scope change.
- `packaging/entrypoint.go` is unchanged.

### Suite proof

- A new harness capability attaches a start to a pseudoterminal and puts a recorder first on `PATH`, named for the platform's opener: `xdg-open` on Linux, `open` on macOS. It appends the URL it receives to a file.
- Cases built on this capability are marked Linux and macOS only. Windows calls `rundll32` directly, with no `PATH` command to intercept.
- No wait-table change. The extra Drush call stays inside the existing `start` and `dr` budgets.

### Commands the criteria use

- `R=/tmp/drupack-qa`, and `dist/drupack` built by `docker build --target artifact --output type=local,dest=dist .`.
- the suite: `python3 tests/conformance ./dist/drupack $R`, and `python` in place of `python3` on Windows.
- selecting one case: append `-k NAME` to the suite command.

---

## Phase 1: Dashboard destination on the printed link

**User stories**: 2, 6, 9

### What to build

`loginLink()` gains a destination-path argument, and `runtime/launch.php`'s mint passes `/admin/dashboard`. The printed line loses its `credentialsRequired($steps)` gate in this phase rather than the next, so every start prints a link and the readiness block needs no guard around that line. Browser eligibility stays as it is; phase 2 takes it. `site_cases.py`'s `test_default_port_first_start_without_credentials` and `initialization_cases.py`'s `FirstStartAndListener` case move their landing-page assertion from `/user/1/edit` to `/admin/dashboard`, and add an assertion that the printed link carries the dashboard destination. Every other assertion in both cases stays.

### Acceptance criteria

- [ ] `grep -rn '/user/1/edit' tests/conformance` prints nothing.
- [ ] `grep -n 'credentialsRequired($steps) ? loginLink' runtime/launch.php` prints nothing.
- [ ] `python3 tests/conformance ./dist/drupack $R -k test_default_port_first_start_without_credentials` exits 0.
- [ ] `python3 tests/conformance ./dist/drupack $R -k test_first_start_creates_the_site_and_serves_through_drush` exits 0.

---

## Phase 2: The browser opens on the dashboard link, on every start that opens one

**User stories**: 1, 3, 4, 5, 7, 8

### What to build

Phase 1 already ungated the mint, so this phase changes browser eligibility alone: it drops the initialization-step requirement, and a plain restart with a terminal on standard input opens a browser on the link that start printed.

The harness gains a pseudoterminal-attached start and the `PATH` recorder from the architectural decisions. A `test_harness.py` case calls the recorder directly and asserts the file it writes, with no built executable.

One new case starts a site, stops it, restarts it under the pseudoterminal and the recorder, and asserts: the second start prints a link carrying the dashboard destination, the recorded URL is that link, and following it lands on the dashboard. A second new case repeats the restart with `--no-browser` and asserts the link still prints while the recorder file is never created.

### Acceptance criteria

- [ ] `python3 -m unittest discover -s tests/conformance -p 'test_harness.py'` passes.
- [ ] `python3 tests/conformance ./dist/drupack $R -k test_later_start_opens_browser_on_dashboard` exits 0 on Linux and on macOS.
- [ ] `python3 tests/conformance ./dist/drupack $R -k test_no_browser_start_opens_nothing` exits 0 on Linux and on macOS.

---

## Phase 3: README's first-run story

**User stories**: 10

### What to build

The paragraph naming the printed link's landing page names the dashboard instead of the account page, and says every start prints a link and opens the browser on it.

### Acceptance criteria

- [ ] `grep -n 'dashboard' README.md` matches the first-run paragraph.
- [ ] `grep -n 'account page' README.md` prints nothing.

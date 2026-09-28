# Plan: the application review's open findings

> Source: [application review](../reviews/2026-09-21-application-review.md),
> findings F07, F08, F11 and F16, listed open in the backlog.

## Decisions

- F08: the install step claims a database it found empty, before `site:install`
  writes to it. A resumed install step on a claimed database runs
  `site:install` again, which drops what the interrupted run left. A database
  never claimed and able to bootstrap is adopted. The `first-install` record goes.
- F16: the development entrypoint copies what `layEngine` copies: the engine's
  `application/` tree without `tests/` and `vendor`, then the two site files
  from `build/` into the docroot's `sites/default`.
- F11: on Windows, a cache root passes when a trusted account owns it and no
  allow entry grants write access to any other account. Trusted accounts are the
  current user, SYSTEM and Administrators. A root the launcher creates gets a
  protected DACL naming those three. `golang.org/x/sys/windows` supplies the
  security calls.
- F07: the engine enables no MCP module since RFC 0001, so the finding has no
  code left to change. The backlog records it as superseded.

---

## Phase 1: a claimed database resumes by reinstalling (F08)

### Acceptance criteria

- [ ] `grep -rn 'first-install\|FIRST_EVER\|firstEver' application tests` prints nothing.
- [ ] `site_data_test.php` passes, with cases for the claim record and its removal at the end.
- [ ] A `PostgresqlLifecycle` case passes: a start resumed at the install step over a claimed database reinstalls it and removes the recipe modules.
- [ ] The existing unclaimed-resume and existing-site cases pass unchanged.

---

## Phase 2: the development copy follows the engine (F16)

### Acceptance criteria

- [x] `build/dev/dev-server.sh` starts a site from an empty data directory, and it answers 200 on `/`.
- [x] After that start, `cmp` finds `application/process.php`, `application/guards.caddy` and `application/support/SiteData.php` identical to their copies under `DATA/runtime/app`.
- [x] `cmp` finds `build/site-settings.php` identical to `DATA/runtime/app/web/sites/default/settings.php`.

---

## Phase 3: Windows cache roots are checked for other writers (F11)

### Acceptance criteria

- [ ] `GOOS=windows go vet ./...` and `GOOS=windows go test -c ./internal/runtime` pass in `launcher/`.
- [ ] Windows-only tests cover a created root passing, a root granting Users write refused, and a NULL DACL refused.
- [ ] The Windows release job's launcher tests pass on this branch.

---

## Phase 4: the backlog names what stays open

### Acceptance criteria

- [ ] `grep -c 'F07\|F08\|F11\|F16' docs/backlog.md` prints 0.
- [ ] `bash build/qa.sh` exits 0.

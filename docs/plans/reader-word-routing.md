# Plan: The launcher routes the reader's words

**Spec:** docs/prd/reader-word-routing.md

## Architectural decisions

- D1: a pure `runtimeArguments(args, application, engine, node)` in the
  launcher replaces `engineArguments`
- D2: `php-cli`, exact `--version`/`-v`, Node words when the file carries Node,
  and the Engine's `php` pass unchanged
- D3: every other argv becomes `php-cli <app>/launch.php` or
  `php-cli <app>/serve.php` with the words kept
- D4: `launch.php`'s `command()` reads `start`, `drush` or `stop`; no word or a
  leading option means `start`
- D5: `-h` or `--help` first prints `HELP`
- D6: a Node word prints the musl message when `site.json` names `node`
- D7: any other word prints `Unknown command` and `HELP`, exit 1
- D8: `HELP` holds the full usage; the help test reads its `Options:` block
- D9: `init()` keeps the binary, CWD and chdir, Engine `php`, `php-server`,
  `browser-open`, `--version` and `runNode`
- D10: every other argv reaches FrankenPHP unchanged
- D11: `usage`, `siteCarriesNode`, `runNode`'s no-Node return and the reader
  branches go
- D12: `DRUPACK_RUNTIME_DRUSH` and `DRUPACK_RUNTIME_STOP` go
- D13: `dev-entry.sh` calls `php-cli /data/runtime/app/launch.php`
- D14: `qa.sh` asserts the `$argv` that `php-cli` hands a script
- D15: an ADR records the routing and amends ADR 0014

The runtime and the launcher ship in one file, so a phase that changes the seam
changes both sides in one commit.

Commands the criteria name:

- Go: `(cd launcher && go test ./...)`
- PHP: `dist/drupacked-demo-linux-amd64 php-cli "$PWD/application/tests/<file>"`,
  with `application/vendor` linked as `build/qa.sh` links it
- Full chain: `git config qa.command`, which is `bash build/qa.sh`

---

## Phase 1: QA checks the `$argv` shape

**User stories**: 5
**Decisions**: D14

### What to build

A probe test file in `application/tests` that asserts the `$argv` it receives,
and a `qa.sh` line that runs it with two arguments.

### Acceptance criteria

- [ ] `dist/drupacked-demo-linux-amd64 php-cli "$PWD/application/tests/<probe>" a b`
  exits 0.
- [ ] The same command with the arguments `a` alone exits 1, naming the
  expected and actual `$argv`.
- [ ] `grep` finds the probe line in `build/qa.sh`, after the other PHP unit
  files.

---

## Phase 2: The Engine routes through `runtimeArguments`

**User stories**: 1, 2
**Decisions**: D1, D2 (Engine rows), D3 (Engine row)

### What to build

`runtimeArguments` replaces `engineArguments` for the Engine, with its table
test. The packaged site still passes `os.Args` unchanged in this phase.

### Acceptance criteria

- [ ] `(cd launcher && go test ./...)` passes, and its new table holds a row for
  the Engine's `php`, `--version`, `-v`, a bare start and a word.
- [ ] `grep -n engineArguments launcher` prints nothing.

---

## Phase 3: A packaged site's words reach `launch.php`

**User stories**: 1, 2, 3, 4, 6, 7, 8, 9, 10
**Decisions**: D1, D2, D3, D4, D5, D6, D7, D8, D9, D10, D11, D12, D13

### What to build

The launcher sends a packaged site's words to `launch.php` through
`runtimeArguments`. `launch.php` reads its mode with `command()` and owns the
full usage. `init()` loses the reader branches, `usage` and the two flags.
`dev-entry.sh` names `launch.php`. One commit, since neither side works alone.

### Acceptance criteria

- [ ] `(cd launcher && go test ./...)` passes, and the table holds the site rows
  of D2 and D3, Node words with and without Node included.
- [ ] `launch_test.php` passes, with cases for each mode word, no word, `-h`,
  `--help`, an unknown word and a Node word with and without `site.json`'s
  `node`.
- [ ] `grep -rn 'RUNTIME_DRUSH\|RUNTIME_STOP\b' runtime application launcher`
  prints nothing, and neither name is in `runtime/environment.txt`.
- [ ] `grep -n 'const usage\|siteCarriesNode\|Unknown command' runtime/entrypoint.go`
  prints nothing.
- [ ] `python3 -m unittest discover -s tests/conformance -p test_environment.py`
  passes.
- [ ] `bash build/qa.sh` passes. Its build runs the argument, Node refusal and
  launcher `--help` cases against the new executable.
- [ ] `build/dev/dev-server.sh` on an empty data directory answers 200 on `/`.

---

## Phase 4: Record the decision

**User stories**: none
**Decisions**: D15

### What to build

ADR 0030 records the routing and amends ADR 0014's consequences. This plan is
deleted in the same commit.

### Acceptance criteria

- [ ] `docs/adr/0030-*.md` exists and ADR 0014 links to it.
- [ ] `test -e docs/plans/reader-word-routing.md` fails.

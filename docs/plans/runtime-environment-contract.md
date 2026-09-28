# Plan: a smaller, declared environment contract

> Source PRD: [runtime-environment-contract](../prd/runtime-environment-contract.md), from
> [RFC 0003](../rfc/0003-runtime-environment-contract.md).

## Architectural decisions

- Each entry point writes its server's Caddyfile from a template before it
  execs into the server. One shared renderer replaces double-underscored
  upper-case tokens and refuses a leftover token or a quoted value.
- The site's Caddyfile lives in Site data's runtime directory. The engine's
  lives in the system temporary directory, named after the folder and the port.
- The runtime's site-server command takes the Caddyfile path as its second
  argument, as the folder-server command does.
- A declaration file beside the PHP extension list holds one line per variable
  that crosses a process, with keyed writer and reader fields.

---

## Phase 1: the site server writes its Caddyfile

**User stories**: 1, 3, 4, 5, 6, 7, 8, 16, 17, 18

### What to build

The shared renderer and the site's Caddyfile template. A start writes the
Caddyfile into Site data's runtime directory and hands its path to the runtime.
`BIND`, `PORT`, `DOCROOT` and `LOG_PATH` stop being exported by the site's
entry point, and the runtime opens a browser when `OPEN` is set, with `BROWSER` gone.

### Acceptance criteria

- [ ] `./dist/mercury-demo-linux-amd64 php-cli "$PWD/application/tests/launch_test.php"`
      passes, with renderer cases for every token replaced, an unknown token
      refused and a quoted value refused.
- [ ] `grep -nE 'DRUPACK_RUNTIME_(BIND|PORT|DOCROOT|LOG_PATH|BROWSER)' application/ runtime/ -r`
      prints nothing.
- [ ] `grep -n '{\$' application/Caddyfile` prints nothing.
- [ ] The conformance case asserting the Caddyfile in Site data's runtime
      directory, holding the served port, passes in the in-image run of `bash build/qa.sh`.
- [ ] The in-image conformance run of `bash build/qa.sh` passes whole.

---

## Phase 2: the engine executable writes its Caddyfile

**User stories**: 2, 19, 20

### What to build

The engine's Caddyfile template, written by the engine entry point into the
system temporary directory before it execs into the folder server. The engine
entry point stops exporting `BIND`, `PORT`, `DOCROOT` and `ID`.

### Acceptance criteria

- [ ] `grep -nE 'DRUPACK_RUNTIME_(BIND|PORT|DOCROOT|ID)' engine/ -r` prints nothing.
- [ ] `grep -n '{\$' engine/Caddyfile` prints nothing.
- [ ] `./dist/mercury-demo-linux-amd64 php-cli "$PWD/application/tests/serve_test.php"` passes.
- [ ] The `EngineExecutable` conformance cases pass, run by `bash build/qa.sh`.

---

## Phase 3: the declaration and its test

**User stories**: 9, 10, 11, 12, 13, 14, 15

### What to build

The declaration file over the fourteen remaining variables, and the contract
test in the QA chain, with failures that name the variable, file and line.

### Acceptance criteria

- [ ] `python3 -m unittest discover -s tests/conformance -p test_environment.py` passes.
- [ ] The same command fails, naming the variable and the file, after one
      `putenv` line is removed from a copy of the checkout; the phase report shows that run.
- [ ] `grep -c '^[A-Z_]' runtime/environment.txt` prints 14.
- [ ] `grep -n test_environment build/qa.sh` prints one line.
- [ ] `grep -c "The variables a start passes between its own processes" docs/backlog.md` prints 0.
- [ ] `bash build/qa.sh` exits 0.

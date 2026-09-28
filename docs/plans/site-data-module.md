# Plan: one module owns Site data

> Source PRD: [site-data-module](../prd/site-data-module.md), from
> [RFC 0002](../rfc/0002-site-data-module.md).

## Architectural decisions

- One final class in the `Drupack\Support` namespace owns every Site data name,
  the listener record, the recorded connection, the steps a start owes and the
  step loop.
- The class touches the filesystem alone. Its tests run against a temporary directory.
- The entry point keeps option precedence, the steps themselves, the step
  report lines and the serving lease's locking.
- Refusal texts and everything a reader sees stay as they are.

---

## Phase 1: the Site data class

**User stories**: 1 to 20.

### What to build

The class with its read side and its write side, as the PRD lists them. The
entry point asks it for every path, reads the listener record and the recorded
connection through it, and runs the steps through its loop. The step runner
takes the first-install and adoption facts and returns whether it found an
existing site. The replaced unit cases move to a new unit file under their
current names, and the full QA chain runs that file.

### Acceptance criteria

- [ ] `./dist/mercury-demo-linux-amd64 php-cli "$PWD/application/tests/site_data_test.php"`
      passes, and holds the thirteen moved cases under their current names plus
      the four loop cases the PRD lists.
- [ ] `./dist/mercury-demo-linux-amd64 php-cli "$PWD/application/tests/launch_test.php"`
      passes, and `grep -c` of each moved case name in that file prints 0.
- [ ] `grep -nE '\$data/|site-installed|installation-progress|site-adopted|first-install|serving\.lock|hash_salt' application/launch.php`
      prints nothing.
- [ ] `grep -n site_data_test build/qa.sh` prints one line.
- [ ] `grep -c "An owner for the Site data layout" docs/backlog.md` prints 0.
- [ ] `bash build/qa.sh` exits 0.

# Consolidate the container lifecycle before adopting testcontainers

Proposed on 2026-09-21. Not implemented.

## The question

The conformance suite drives Docker through 28 raw `subprocess.run(["docker", ...])` calls across five modules. Two of those modules carry the same container lifecycle, line for line. Does a maintained package replace that code, and is the dependency worth it?

## What ships today

Every import under `tests/conformance/` comes from the standard library. The repo carries no Python manifest and no lockfile of any kind. `.github/workflows/release.yml` has no `pip install` step and no `actions/setup-python` step, so all four jobs run whichever Python the runner image ships.

The 28 invocation sites fall into three groups:

- Database lifecycle, 11 sites. `initialization_cases.py:530,539,547,560,567,569` and `server_database_cases.py:41,49,68,75,77`. Both spell the same shape: `docker run -d`, read the mapped port, poll for readiness, `docker logs` into the results directory, `docker rm -f` on both the failure path and teardown.
- SQL through `docker exec`, 6 sites. `initialization_cases.py:578,646,656,662` and the readiness probes at `server_database_cases.py:137,149`.
- One-shot and peer containers, 11 sites. `network_cases.py:77,93,105,122,124,143,148`, `offline_cases.py:37,67`, `launcher_cases.py:424,443`.

Every image is pinned by sha256 digest, following `docs/rfc/test-architecture.md`.

## Ecosystem survey

Each row was fetched on 2026-09-21 from `https://pypi.org/pypi/<name>/json` and cross-checked against the project's GitHub repository.

| Package | Grounding | Last release | Maintenance | Fit | Verdict |
|---|---|---|---|---|---|
| `testcontainers` 4.15.0 | `pypi.org/pypi/testcontainers/json`; tag `testcontainers-v4.15.0` | 2026-07-24 | active, repo pushed 2026-09-14 | Covers the 11 lifecycle sites and the 6 exec sites | best fit |
| `docker` 7.2.0 | `pypi.org/pypi/docker/json` | 2026-07-09 | active, repo pushed 2026-09-21 | Bare SDK; the suite would keep writing its own lifecycle | reject |
| `python-on-whales` 0.81.0 | `pypi.org/pypi/python-on-whales/json` | 2026-03-09 | active, repo pushed 2026-09-18 | Typed CLI wrapper; no lifecycle help; pulls compiled pydantic-core | reject |
| `pytest-docker` 3.2.5 | `pypi.org/pypi/pytest-docker/json` | 2025-11-12 | quiet 10 months | Requires pytest and a compose file | reject |
| `pytest-postgresql` 9.1.0 | `pypi.org/pypi/pytest-postgresql/json` | 2026-09-04 | active | Requires pytest; supervises a local `postgres` binary through mirakuru | reject |
| `pytest-mysql` 5.0.0 | `pypi.org/pypi/pytest-mysql/json` | 2026-09-08 | active | Requires pytest; same local-binary design | reject |
| `pytest-docker-tools` 3.1.10 | `pypi.org/pypi/pytest-docker-tools/json` | 2026-07-21 | bursty, one maintainer, ~16 months between real fixes | Requires pytest | reject |
| `pytest-container` 0.4.4 | `pypi.org/pypi/pytest-container/json` | 2025-06-30 | slow, last real commits Dec 2025 | Asserts image contents; wrong problem | reject |
| `docker-compose` 1.29.2 | `pypi.org/pypi/docker-compose/json` | 2021-05-10 | abandoned on PyPI; the active repo is the Go V2 rewrite | Compose orchestration | reject |
| `ephemeral-port-reserve` 1.1.4 | `pypi.org/pypi/ephemeral-port-reserve/json` | 2021-02-11 | dormant 5 years | `socket.bind(('', 0))` already does this | reject |

Five candidates fall to one fact: they require pytest, and this suite is `unittest`. Two more supervise a local database binary rather than a container, so they cannot honour the digest-pinned images.

## Adoption decision: hold

Chosen package if this proceeds: `testcontainers` 4.15.0. Nothing else in the survey fits a `unittest` suite that needs real containers.

The survey passes every viability check. Reversibility costs a call-site rewrite of two classes, about 90 lines, with the old code in git history. Blast radius is 17 sites in 2 classes. The behavioural gaps resolve to three items, listed below.

The cost decides it. Adoption creates this repo's first Python dependency surface: a manifest, a lockfile decision, and `actions/setup-python` plus `pip install` in four jobs across Linux, Windows and macOS. The tree runs to roughly ten packages. `testcontainers` requires Python 3.10 or newer, which pins three runner images. Its Ryuk reaper adds a container pull and a running container per suite run.

Against that, the duplication is two copies of about 45 lines, removable in-repo for about 40 lines of change.

`docs/rfc/test-architecture.md` merged on 2026-09-20 with a stated aim of digest-pinned images, one cache per run, and independence from machine state. Four days later, the charge outweighs the gain.

### Reversibility

If `testcontainers` becomes unmaintained in two years, swapping out costs a call-site rewrite of the two database classes. The failure mode this answers is package abandonment. The current code stays recoverable from git history, so no re-implementation from scratch is needed.

### Debug story

A database container fails to come ready six months from now. The engineer reads the container log that the wrapper still writes into the results directory. They then reproduce it with `docker run` against the same pinned digest. Diagnosis needs no reading of package source.

## Decision: consolidate in-repo instead

`server_database_cases.py:26-78` already proves the lifecycle is parameterizable. It drives MySQL and PostgreSQL from one base through `DATABASE`, `IMAGE`, `CONTAINER_PORT`, `RUN_ENV` and `_ready_probe`.

Move that base into `harness.py` as a managed-container helper. Have `initialization_cases.PostgresqlLifecycle` inherit it. The duplication goes for about 40 lines of change and no new dependency.

That work belongs to `docs/rfc/conformance-case-scaffolding.md`, which already carves the container lifecycle out of its own scope and points here.

## What would reverse this decision

Either of these makes the trade favour adoption:

- A third database backend arrives. Three copies of a lifecycle justify a package where two justify a base class.
- CI shows a real container leak that the current `rm -f` paths miss. Ryuk answers that directly, and nothing in-repo does.

The plan below is held ready for that day.

## Held migration plan

### Call-site inventory

```
initialization_cases.py:530   first    docker run -d, postgres
initialization_cases.py:539   first    docker port, mapped host port
initialization_cases.py:547   first    pg_isready poll loop
initialization_cases.py:560   first    rm -f on the setup failure path
initialization_cases.py:567   first    docker logs into the results directory
initialization_cases.py:569   first    rm -f on teardown
initialization_cases.py:578   middle   docker exec psql, _create_database
server_database_cases.py:41   middle   docker run -d, parameterized backend
server_database_cases.py:49   middle   docker port
server_database_cases.py:68   middle   rm -f on the setup failure path
server_database_cases.py:75   middle   docker logs
server_database_cases.py:77   middle   rm -f on teardown
server_database_cases.py:149  middle   pg_isready probe
initialization_cases.py:646   last     docker exec psql, CREATE TABLE in the refusal case
initialization_cases.py:656   last     docker exec psql, table count assertion
initialization_cases.py:662   last     docker exec psql, table count assertion
server_database_cases.py:137  last     mysql probe; readiness moves to a log regex
network_cases.py:77           blocked  run to completion, stdin-piped source, exit code
network_cases.py:93           blocked  peer container on an internal network
network_cases.py:105          blocked  log tail used as the readiness signal
network_cases.py:122          blocked  log capture for the peer container
network_cases.py:124          blocked  rm -f for the peer container
network_cases.py:143          blocked  docker network create --internal
network_cases.py:148          blocked  docker network rm
offline_cases.py:37           blocked  run to completion, --network none, four mounts, --user
offline_cases.py:67           blocked  rm -f on the timeout path
launcher_cases.py:424         blocked  run to completion, --tmpfs /cache:size=4m
launcher_cases.py:443         blocked  rm -f on the timeout path
```

The eleven blocked sites run a container to completion and read its exit code. `testcontainers` models long-lived services, so they stay on raw Docker whatever happens here.

### Behavioral gap analysis

| The suite does | testcontainers does | Resolution |
|---|---|---|
| `docker run -d -p 127.0.0.1::5432` | `with_exposed_ports(5432)`, then `get_exposed_port(5432)` | adopt |
| `postgres:17.11@sha256:...` | Image string forwards to docker-py | adopt |
| Parse `docker port` output | `get_exposed_port` | adopt |
| `pg_isready` poll loop | `ExecWaitStrategy` running `psql -c 'select version();'` | accept: both prove the server answers |
| `mysqladmin`-style probe | `LogMessageWaitStrategy`, a regex over container logs | accept: readiness moves from a command to a log line |
| `except Exception: rm -f; raise` | Context manager plus the Ryuk reaper | adopt, a stronger guarantee |
| `docker exec psql -c ...` | `.exec(command)` | adopt |
| `docker logs` into the results directory | `get_logs()` returns a bytes pair | wrapper, about two lines |
| Every image pinned by digest | Ryuk defaults to `testcontainers/ryuk:0.8.1`, pinned by tag | config: set `RYUK_CONTAINER_IMAGE` to a digest, or `TESTCONTAINERS_RYUK_DISABLED` |
| Discovery imports every case module | A module-level import would crash the offline run | wrapper: import inside `setUpClass`, after the tool gate |

That last row is the sharp one. `offline_cases.py:36-54` runs the suite inside `python:3.13-slim` with `--network none` and the repo mounted read-only, and `__main__.py:27` discovers by importing every `*_cases.py`. Nothing in that container can install a package.

### Migration sequence

1. Add the manifest, the lockfile and the CI install steps. Nothing else changes. Validation: all four jobs stay green with the dependency installed and unused.
2. Migrate `PostgresqlLifecycle` (the `first` sites). PostgreSQL uses `ExecWaitStrategy`, closest to the current probe. Validation: its three cases pass, and the results directory still holds `postgres.log`.
3. Migrate the `_create_database` helper at `:578` to `.exec()`. Validation: the two cases that call it pass.
4. Migrate `server_database_cases.py` on the PostgreSQL path. Validation: `PostgresqlServerDatabase.test_first_start_then_restart` passes.
5. Migrate the MySQL path. Readiness changes from a command to a log regex, which is the one novel behaviour, so it goes last. Validation: `MysqlServerDatabase.test_first_start_then_restart` passes, and the run holds no orphan container.
6. Migrate the three `docker exec psql` calls in the refusal case. Validation: the refusal case still refuses.

### Rollback plan

1. Revert the workflow and manifest commit. Nothing depends on them yet.
2. Revert the commit. `PostgresqlLifecycle` returns to its own `setUpClass`.
3. Revert the commit. `_create_database` returns to `docker exec`.
4. Revert the commit. The PostgreSQL backend returns to the shared base.
5. Revert the commit. MySQL returns to its command probe, which is the reason this step is last.
6. Revert the commit. The three assertions return to `docker exec`.

Every step reverts by itself, because each one leaves the suite green. No step requires re-implementing deleted code.

### Test strategy

The code being replaced sits in `setUpClass` and `tearDownClass` and has no tests of its own today. The five cases below exercise it indirectly and keep their names.

| Existing test | Bucket | Replacement |
|---|---|---|
| `PostgresqlLifecycle.test_first_start_then_interrupted_recovery_without_reinstalling` | preserve-as-is | Same name, same assertions; the fixture beneath it changes |
| `PostgresqlLifecycle.test_first_start_never_reinstalls_a_database_that_already_holds_a_site` | preserve-as-is | Same name, same assertions |
| `PostgresqlLifecycle.test_first_start_refuses_a_database_that_holds_other_tables` | rewrite-one-for-one | Same name, same assertion intent; three `docker exec psql` calls become `.exec()` |
| `MysqlServerDatabase.test_first_start_then_restart` | preserve-as-is | Same name; readiness moves to a log regex underneath |
| `PostgresqlServerDatabase.test_first_start_then_restart` | preserve-as-is | Same name, same assertions |

Nothing is deleted. Five cases sit under this cluster, below the 20 that would demand a parallel-run period, and none traces to a production incident.

One new case has no counterpart: assert the suite still imports cleanly with `testcontainers` absent, which is the condition the offline container runs under.

## Consequences of holding

- The suite keeps 28 raw Docker calls and gains a shared base for 11 of them.
- The repo keeps its standard-library-only Python surface, and CI keeps its four jobs without an install step.
- Container cleanup keeps relying on the `except Exception: rm -f` paths and the timeout kills. A process killed between `docker run` and that handler leaves a container behind.
- This survey stays valid for roughly six months. Re-fetch the grounding before acting on it.

## Considered options

- Adopt `testcontainers` now, for all 17 database sites. It removes the poll loops and hardens cleanup. It charges the first dependency surface for a two-copy duplication.
- Adopt `docker-py` and keep writing the lifecycle. The footprint is smaller, and the lifecycle code stays, which is the code this exercise set out to remove.
- Move the suite to pytest and use `pytest-docker-tools`. It opens the fixture ecosystem. It rewrites ten case modules and depends on a project with one maintainer and long gaps between fixes.
- Change nothing. Free, and the duplicated 45 lines stay duplicated.

## Out of scope

- The eleven blocked call sites, which no surveyed package addresses.
- The case scaffolding itself, in [conformance-case-scaffolding.md](conformance-case-scaffolding.md).
- Cross-language replacement of the suite, which is a service-boundary question.

# One server per Site data, claimed by a lease

Accepted on 2026-09-21. Replaces the HTTP ownership probe of the same date.

## Context

Two FrankenPHP processes over one database and one runtime directory corrupt
both. The guard asked the port for an identity token and read a 204 as proof,
which any local program can answer. A second start on another port found that
port free and served anyway.

## Decision

- A start takes an exclusive lock on `serving.lock` in Site data and holds it
  until the server exits. The lock lives on the open file description, so it
  survives the exec into the server and the kernel releases it on a kill.
- A start that cannot take the lease refuses. It hands the reader over to the
  running site instead when a person is watching and the site is installed.
- The same lease covers initialization, so the separate startup lock goes.
- The port is probed only for the question it can answer, whether another
  program holds the address.
- `/.drupack-id` stays, as the readiness probe the entry point polls. Only a 204
  from it counts as ready.

## Consequences

Identity rests on filesystem permissions rather than on a network answer. A
listener that answers everything receives no login link.

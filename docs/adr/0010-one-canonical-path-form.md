# Paths take one canonical form per language

Accepted on 2026-09-21.

## Context

Windows produced backslashes in rendered URLs, drive letters in icon paths, and
a container key that changed with the path spelling. Each defect was a separate
fix until the form itself was named.

## Decision

- Canonical form: absolute, forward slashes, no trailing separator, no `.` or
  `..` segment. Every path Drupack computes, exports or prints takes it.
- Go keeps the native form inside the launcher and converts at the exported
  runtime variables.
- PHP resolves paths through `Symfony\Component\Filesystem\Path`, reached from
  the application's own autoloader.
- A minted cache segment matches `^[a-z][a-z0-9.-]{0,31}$`, stated once in
  `internal/runtime` and enforced by `cmd/pack` at build time.
- Drupal gets one service override per defect, registered from `settings.php`.
  Both overrides run on every platform, so Linux exercises the shipped path.

## Consequences

A conformance crawl reads a fixed page set and fails on a drive letter, a
backslash or an application root prefix in any rendered address.

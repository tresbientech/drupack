# The Windows cache root stays ASCII

Accepted on 2026-09-21.

## Context

A Windows account named with non-ASCII characters broke every start: PHP
startup resolves its `PHPRC`-derived configuration path through the ANSI code
page, so the extension directory could not be read and 13 extensions failed to
load.

## Decision

- The Windows cache root is resolved to an ASCII path before the server sees
  it, through the directory's 8.3 short name.
- The rungs are tried in order: the named or default root, its short name, then
  the temporary directory and its short name.
- A root that cannot be made ASCII stops the start with a message naming
  `DRUPACK_CACHE_DIR`.
- Unix keeps its own root rules, which the same function selects by platform.

## Consequences

Accented Latin, Cyrillic and CJK account names all serve. A machine with 8.3
name creation disabled falls through to the temporary directory.

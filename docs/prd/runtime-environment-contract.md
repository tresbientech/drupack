# PRD: a smaller, declared environment contract

Source: [RFC 0003](../rfc/0003-runtime-environment-contract.md), design settled on 2026-09-28.

## Problem Statement

A maintainer who renames or adds a value passed between a start's processes
edits it in up to five files across Go, PHP, shell and two Caddyfiles. Nothing
lists those files. When the Caddyfile side is missed, Caddy reads an empty
string, starts anyway, and serves the wrong root or writes no log.

A reader sees only that failure: a site that answers with an error or keeps no
log, and no message naming why.

## Solution

The two entry points write their server's Caddyfile themselves, from a template
with named tokens. The values Caddy alone reads stop travelling through the
environment, and `BROWSER` merges into `OPEN`. The fourteen variables that must
still cross are declared in one file, and a fast test checks every writer and
reader against it.

A reader sees no change, except one new file in Site data's runtime directory:
the Caddyfile the server parsed.

## User Stories

1. As a maintainer, I want the site server's configuration written by the process that computes its values, so that no placeholder resolves to empty.
2. As a maintainer, I want the engine executable's server configuration written the same way, so that both servers follow one rule.
3. As a maintainer, I want one renderer shared by both entry points, so that token handling lives in one place.
4. As a maintainer, I want the renderer to refuse a template token it has no value for, so that a missing value fails before the server starts.
5. As a maintainer, I want the renderer to refuse a value holding a double quote, so that a value cannot break the configuration's syntax.
6. As a maintainer, I want the runtime to take the configuration path as an argument for the site server, as it already does for the folder server, so that both commands work alike.
7. As a maintainer, I want the shared request guards imported by absolute path, so that a configuration written elsewhere still loads them.
8. As a maintainer, I want the browser decision carried by the presence of the link, so that two variables cannot disagree.
9. As a maintainer, I want every variable that crosses a process declared once with its writers and readers, so that I can see the whole contract in one file.
10. As a maintainer, I want a test that fails when code names an undeclared variable, so that a new variable cannot slip in unlisted.
11. As a maintainer, I want a test that fails when a declared writer stops writing or a declared reader stops reading, so that a rename caught on one side fails.
12. As a maintainer, I want each failure to name the variable, the file and the line, so that I know the edit that fixes it.
13. As a maintainer, I want a missing declaration to print a line I can paste, so that the common fix takes seconds.
14. As a maintainer, I want the contract test to run in the QA chain without a built executable, so that it fails before the long stages start.
15. As a maintainer, I want a test that no template keeps an environment placeholder, so that the old mechanism cannot come back quietly.
16. As a reader, I want every start to serve exactly as before, so that nothing I rely on changes.
17. As a reader whose site misbehaves, I want the parsed server configuration left in Site data, so that I, or whoever helps me, can read what the server ran with.
18. As a reader with a space in my Site data path, I want the site to keep serving, so that the path I chose still works.
19. As a reader starting the engine executable in a project folder, I want the folder left unchanged outside its public files, so that the engine keeps its promise.
20. As a reader restarting the engine executable often, I want its temporary configuration overwritten rather than piled up, so that my temporary directory stays clean.

## Implementation Decisions

- A shared renderer in the process helpers takes a template path, a map of token names to values, and a destination. It replaces every token, refuses a value holding a double quote, refuses output that still holds a token, and writes through a staging file and a rename.
- Tokens use the settings template's form: a double-underscored upper-case name.
- The site entry point writes the site's configuration into Site data's runtime directory on every start, under the serving lease. `drush` writes none.
- The engine entry point writes the folder's configuration into the system temporary directory, under a name derived from the folder and the port.
- The runtime's site-server command takes the configuration path as its second argument.
- Both templates import the shared guards by an absolute path the renderer supplies.
- `BIND`, `PORT`, `DOCROOT` and `LOG_PATH` stop being exported. `ID` and `FILES_DIR` keep their exports for the readers outside the server configuration.
- `BROWSER` goes. The runtime opens a browser when `OPEN` is non-empty.
- A declaration file beside the PHP extension list holds one line per remaining variable, with keyed writer and reader fields.
- A Python unit test reads the declaration and the tracked sources under the product directories, tests excluded, and finds writes and reads by per-language pattern.
- The existing refusals for a double quote in `--data-dir` and `--files-dir` stay, with their current wording.

## Testing Decisions

- A good test gives the renderer a template and values and checks the written file, or gives the contract test a checkout and checks its verdict. None depends on how either works inside.
- Renderer cases join the entry point's unit file: every token replaced, an unknown token refused, a quoted value refused.
- The contract test joins the QA chain beside the harness's own unit tests.
- One conformance assertion checks that a start leaves its configuration in Site data's runtime directory, holding the port it serves on.
- The conformance suite runs unchanged otherwise, and must pass whole. Its listener, private path, public files, log, space-in-path and engine cases cover the served behavior.
- Prior art: the settings template and its writer; the PHP extension list and its checker; the harness unit tests.

## Out of Scope

- Moving `DRUSH` into argv, which changes the option parser ADR 0014 governs.
- Merging `NAME`, `SITE_VERSION` and `LAUNCHER` into one value.
- Checking the form of a value, such as canonical slashes.
- The development entrypoint, whose file list the backlog's open review findings already cover.
- The runtime watcher of RFC 0004.

## Further Notes

The backlog entry "The variables a start passes between its own processes"
closes when this ships. Its lean, a table checked by a test, survives as the
second half of this work.

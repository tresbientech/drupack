# PRD: one module owns Site data

Source: [RFC 0002](../rfc/0002-site-data-module.md), design settled on 2026-09-28.

## Problem Statement

A maintainer who changes how a start records its state edits the entry point in
many places. Twelve Site data names are spelled across the entry point, half
through helpers and half inline. The initialization state machine lives in three
functions: one reads the state files, one writes progress and the finished
marker, and one writes the adoption marker. A new state file needs three
coordinated edits, and no test checks that finishing clears every file a later
start reads.

A reader never sees this code. They see its failures: a start that repeats a
step against their database, or one that refuses a Site data directory it
should resume.

## Solution

One PHP class owns a Site data directory. Its read side names every path,
returns the listener record whole or refuses, and returns the recorded database
connection. Its write side reports the steps a start owes and runs them in a
loop it owns. The loop records progress after each step and clears the state
files after the last one.

The entry point keeps option precedence, the steps themselves, and the words a
start prints. A reader sees no change in behavior or output.

## User Stories

1. As a maintainer, I want every Site data file name spelled in one class, so that a rename is one edit.
2. As a maintainer, I want the entry point to ask for a path by name, so that it never builds one from a string.
3. As a maintainer, I want the steps a start owes computed in one method, so that the resume rules live in one place.
4. As a maintainer, I want the step loop owned by the module, so that no caller can run a step without recording progress after it.
5. As a maintainer, I want the finished marker written and every other state file removed by the module, so that a later start never reads a stale file.
6. As a maintainer, I want the adoption fact passed to each later step, so that the modules step reads a value instead of a file.
7. As a maintainer, I want the first-install fact passed to each step, so that the install step reads a value instead of a file.
8. As a maintainer, I want the listener record validated where it is read, so that a partial record refuses in one place.
9. As a maintainer, I want the recorded connection read by the module, so that the settings require and its loader variables live in one place.
10. As a maintainer, I want the module tested against a real temporary directory, so that the tests exercise the same file operations production does.
11. As a maintainer, I want each replaced unit case rewritten under its old name, so that the knowledge it holds survives the move.
12. As a maintainer, I want a test that interrupts the loop mid-way, so that the resume guarantee has a fast check.
13. As a maintainer, I want the conformance suite unchanged, so that it keeps proving the executable's behavior from outside.
14. As a reader starting an installed site, I want the same output as before, so that nothing I rely on moves.
15. As a reader whose first start crashed, I want the next start to resume at the step that failed, so that my database never receives a step twice.
16. As a reader whose Site data holds a database without settings, I want the same refusal naming the file to restore, so that I keep my only copy.
17. As a reader with a damaged progress or listener file, I want a refusal naming the file to remove and the executable to start again, so that I can recover alone.
18. As a reader running `drush` while the site serves, I want `drush` to read Site data and write nothing, so that the running server is undisturbed.
19. As a reader who named a files directory once, I want later starts and `drush` to keep it, so that my files stay where I put them.
20. As a reader adopting a database that already holds a site, I want the modules step skipped, so that my site's modules stay as they are.

## Implementation Decisions

- A new final class in the `Drupack\Support` namespace, beside the existing support classes, holds the whole module.
- Its read side:
  - one method per path a caller needs: settings, database, secret, application, default files directory, logs, runtime and lease;
  - a method that creates the runtime, private, tmp, config and logs directories;
  - a listener method that returns the record with all three fields, returns nothing when absent, or refuses;
  - a method that writes the listener record;
  - a connection method that returns the recorded database block.
- Its write side:
  - a steps method, with the rules the current resume function has, including the first-install record for a new server database;
  - an initialize method taking the steps and a callback. The callback receives the step, the first-install fact and the adoption fact, and returns whether the step found an existing site.
- The initialize method records the remaining steps after each callback returns. After the last step it writes the finished marker and removes the progress, adoption and first-install files. It returns the adoption fact.
- The state file names become private to the class.
- The step runner in the entry point returns a bool and takes the two facts as arguments. It no longer reads or writes a state file.
- Option precedence stays in the entry point: the listener and files-directory merges take the array the listener method returns.
- The step report lines stay in the entry point, printed by the callback.
- Refusal texts keep their current wording and keep naming the executable.
- The serving lease's locking logic stays in the entry point and takes its path from the module.

## Testing Decisions

- A good test builds a Site data directory on disk, calls the module, and checks a return value, a file, or the calls a recording callback received. It never reads a private name except through the module's own methods.
- A new unit file covers the module and joins the full QA chain.
- Thirteen existing entry-point cases move to the module under their current names. The case on state file names is deleted, since those names become private.
- New cases cover the loop:
  - a finished loop leaves no progress, adoption or first-install file;
  - adoption passes to every later step;
  - a callback that throws leaves progress naming the failed step onward;
  - first-install passes true only for a database the steps method recorded as new.
- The listener and files-directory merge cases stay in the entry-point unit file.
- Prior art: the entry-point unit file already builds temporary Site data directories. The shared PHP case runner provides the assertions.
- The conformance suite runs unchanged and must pass whole.

## Out of Scope

- Site data compatibility across releases, the backlog's first entry.
- The engine executable, which serves a project folder and has no Site data.
- The environment contract between processes, RFC 0003.
- The runtime watcher, RFC 0004.
- Changes to any message a reader sees.

## Further Notes

The backlog entry "An owner for the Site data layout" leaned toward helpers
without a class. The RFC's considered options record why the class won: helpers
fix the spelling and leave the state transitions split over three functions.
That entry closes when this ships.

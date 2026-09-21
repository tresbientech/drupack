# Windows path handling and one application extraction

Covers [the Windows path RFC](../rfc/windows-path-normalisation.md) and
[ADR 0002](../adr/0002-shared-application-extraction.md).

## Problem Statement

A person downloads Drupack and double-clicks it on Windows. About half a minute
passes with no sign that anything is happening. The site then opens with no
icons anywhere, and the navigation bar shows empty gaps where its icons belong.
Component images fail to load. Their address carries the reader's own folder
path, so the page source publishes where their files live.

Starting a second version leaves the first version's unpacked copy behind. After
a week of releases, over two gigabytes sit in temporary storage and in Site data.
Nothing removes any of it. Each new site unpacks its own 200 MB copy of the same
application.

None of this happens on Linux or macOS.

## Solution

Icons render. Image addresses carry no disk path. The first start of a release
reports what it is doing and how far along it is.

Later starts of that release skip the unpacking. A second site of the same
release reuses the first one's copy. An upgrade removes what the previous layout
left behind and says how much space it freed. A command reports and clears the
cache on demand.

## User Stories

### Rendering on Windows

1. As a Windows user, I want the navigation bar icons to appear, so that I can
   tell the controls apart.
2. As a Windows user, I want component images to load, so that the demo site
   looks as advertised.
3. As a Windows user, I want no image address to carry my folder path, so that a
   shared page hides my disk layout.
4. As a Linux or macOS user, I want no visible change, so that a Windows repair
   costs me no regression.
5. As an upgrader, I want the repair to survive a Drupal core update, so that the
   fault stays gone.
6. As a site owner, I want the repair always in place, so that no settings page
   switches it off.

### First start

7. As a first-time starter, I want to see the unpacking running, so that I do not
   think it hung.
8. As that person, I want to see how far it has got, so that I can judge the wait.
9. As a returning starter, I want no unpacking at all, so that a later start is
   quick.
10. As a person on a slow disk, I want one write per release, so that a first
    start halves.
11. As a person whose start is interrupted, I want the next start to unpack
    again, so that nothing serves partially.

### Disk use

12. As a two-site owner, I want the second site to reuse the first copy, so that
    one release means one copy.
13. As a person short on space, I want old releases to leave on their own, so
    that use self-limits.
14. As an upgrader, I want the previous layout removed, so that its space comes
    back.
15. As that person, I want the freed amount reported, so that I can see it
    happened.
16. As a careful person, I want another program's files untouched, so that
    cleanup stays inside what Drupack wrote.
17. As a person wanting space now, I want a command that reports and clears the
    cache, so that I skip the wait.

### Site data and upgrades

18. As an existing site owner, I want my uploaded files to keep working, so that
    an upgrade loses nothing.
19. As that owner, I want file addresses to survive a port change, so that cached
    pages stay correct.
20. As a two-site owner, I want neither site able to write into the other's copy,
    so that they cannot corrupt each other.
21. As a person stopping with Ctrl+C, I want the shared copy to stay, so that the
    next start is quick.

### Maintenance

22. As a maintainer, I want the Windows rules in pure functions, so that I can
    test them without Windows.
23. As a maintainer, I want those rules tested everywhere, so that a Linux run
    proves Windows behaviour.
24. As a maintainer, I want a case that fails on Windows today and passes on
    Linux today, so that it reaches the fault.
25. As a maintainer, I want the repair to read as two small overrides, so that
    core divergence stays obvious.
26. As a release engineer, I want one release, so that a regression has one
    version to blame.

## Implementation Decisions

### Deep modules

Two modules hold every rule. Both take strings and return values. Neither reads
a Drupal service, a container or the filesystem.

- A path module answers what an absolute application path looks like relative to
  the application root. It converts separators and strips the root prefix. The
  result matches what a forward-slash platform already produces.
- A source module answers whether a URI names a local file this product may
  read. It permits a one-character scheme, since no PHP stream wrapper has a
  one-character name. A Windows absolute path starts with one. Every other
  scheme and any host stays refused, so remote sources and writable site storage
  stay refused. The module fails closed.

### Adapters and wiring

Three adapters hold no logic. One replaces the icon reader's file access with a
call to the source module. One replaces the component discovery step that
records a directory with a call to the path module. One registers the other two
with the dependency injection container.

That registration changes the class of two existing service definitions and
leaves their constructor arguments alone. The application declares it through a
settings entry the kernel already reads, so no module has to be installed.

The application's autoloader gains one namespace. The build regenerates that
autoloader after the runtime files arrive and before the seed site installs.

### One extraction per release

The bundled web server no longer carries the application inside itself. The
product's own entry point carries it. The entry point unpacks it into a per-user
cache keyed by the application's checksum, and writes a completion marker last.
A later start that finds the marker skips the unpacking.

The entry point changes its working directory to that cache. It does not name
the cache to the server, because the server deletes any directory it is told
about when it shuts down cleanly.

### Public files

Public file storage moves out of the application directory. The site points its
public file path at its own Site data. It supplies a root-relative base address
for those files, so no cached address carries a host or a port. The web server
maps the public file address prefix onto Site data. Nothing writes into the
shared application.

### Launcher behaviour

The launcher reports unpacking progress as bytes written against a declared
total. It also removes what the previous layout left behind. A removal applies
only to a directory whose name matches the old pattern exactly and that holds a
file this product ships. A command reports and clears cache entries on demand.

## Testing Decisions

### What a good test asserts

A good test here asserts external behaviour. For the deep modules that means a
table of inputs and expected outputs, with no reference to how the answer is
reached. For the product it means the rendered page and the files on disk,
rather than the classes the container holds.

### Unit tests

The two deep modules get exhaustive unit tests. The inputs include synthetic
Windows values run on Linux, so a Linux run proves the Windows rules.

The path module is tested with a mixed-separator absolute path, a uniform
absolute path, an already relative path, and a root carrying a trailing
separator.

The source module is tested with:

- both Windows absolute forms
- a POSIX absolute path
- writable site storage
- a remote address
- a network share
- a PHP pseudo stream

These tests run through the shipped executable's command-line PHP mode. They
need no PHP on the host and no test framework. The conformance suite already
runs a probe that way, which is the prior art.

The adapters get no tests of their own. They hold no logic, and the conformance
cases cross all three of them.

### Conformance cases

The conformance suite gains a case file with four assertions. Icon elements
carry SVG content. No rendered address contains a drive letter. No rendered
address contains a backslash. A component example asset answers with a success
status.

The suite's existing site cases are the prior art for starting a site and
reading a page. The existing replacement cases are the prior art for asserting
on the unpacked directory, and they move with the new location.

The suite runs on all three platforms. Each new assertion fails on Windows
before the change and passes on Linux before the change. That difference proves
the case reaches the fault.

## Out of Scope

- Filing either defect upstream, or landing a patch in Drupal core or Canvas.
- Hardening the page builder module that turns a component path into an address
  without checking that the path is relative.
- Normalising the address recorded beside each icon, which carries a separator
  the web server already serves.
- Any change to the login link, which already sends a reader to the dashboard.
- A tray application, a service installer, or any other Windows integration.

## Further Notes

Both defects were reported upstream before this work started. The same person
reported both, on a different Windows stack. The core report carries a merge
request that does not repair its own fault. The page builder report names the
wrong project.

The analysis in the RFC identifies both causes precisely. It stays available if
the owner later chooses to contribute it.

The measurements in the ADR come from a reporting machine running the current
release. They replace the figures taken when the ADR was first written, and the
application has grown since.

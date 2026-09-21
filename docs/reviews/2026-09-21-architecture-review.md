# Application architecture review

## Scope

Question: which architectural boundaries need clearer ownership?

Reviewed on 2026-09-21 at commit `192002efe563f910b8d93f7c4142d366be706c6f`.
This document records proposed decisions from an application review. They are not accepted implementation decisions.
Tests were excluded. The review changed no application code.

The [complete findings](2026-09-21-application-review.md) contain severity, triggering conditions, evidence, and validation limits.
Finding identifiers below refer to that document.

## Current structure

Question: how does a packaged site start?

1. The Go launcher extracts the native runtime into a user cache.
2. It separately extracts the Drupal application into a shared application cache.
3. The native entrypoint selects a command and invokes the PHP launcher.
4. PHP resolves Site data and performs initialization or command preparation.
5. PHP invokes Drush or starts the native runtime as a web server.
6. Caddy reads the exported environment and serves Drupal with the selected Site data.

The split between packaged application code and persistent Site data supports multiple sites per release.
The Go/PHP boundary matches their existing responsibilities. The principal gaps concern ownership of state across processes and releases.

Sources: [launcher](../../packaging/launcher/main.go), [native entrypoint](../../packaging/entrypoint.go), and [PHP launcher](../../runtime/launch.php).

## A01: own the runtime and application as one release

Question: which components must remain compatible and available together?

The launcher prepares the native runtime and Drupal application independently.
Runtime fallback can select an older executable while application preparation selects the current application.
Runtime cleanup removes previous entries immediately. Application cleanup uses a separate retention period or an explicit clean command.
Neither cache tracks the lifetime of a serving process.

Proposed decision:

- Give one release identity ownership of both component identities.
- Acquire usage locks before handing either component to a process.
- Hold those locks while the process needs its release files.
- Make automatic and explicit cleanup skip releases with active users.
- Fall back only to a compatible complete release, or stop.

This retains the shared extraction design and gives cleanup a direct answer to whether an entry is still required.

Findings: F03 and F06.
Sources: [runtime cache](../../packaging/launcher/internal/runtime/cache.go) and [application cache](../../packaging/launcher/internal/runtime/app.go).

## A02: give one component ownership of Site data transitions

Question: who decides whether a site is new, incomplete, adopted, or ready?

Initialization state spans generated settings and several marker files.
The `first-install` marker cannot distinguish an installation completed before interruption from a foreign database found on first contact.
The startup lock covers initialization alone. Established sites can start on different ports without an exclusive serving lease.
Settings writes also occur outside initialization.

Proposed decision:

- Centralize state reads and transitions in a Site data component.
- Record database ownership separately from installation progress.
- Publish state changes through atomic file replacement.
- Acquire a serving lease independently of the selected network address.
- Define which commands may run concurrently with serving and initialization.

Keep the implementation proportional to the actual states. A general workflow framework is unnecessary for the current fixed sequence.

Findings: F05, F08, and F13.
Source: [PHP launcher](../../runtime/launch.php), `remainingSteps()`, `initialize()`, and the main startup path.

## A03: make database compatibility a release boundary

Question: when may a release open existing Site data?

An installation marker bypasses initialization, but it carries no release or schema compatibility information.
The application does not implement the version record described in `CONTEXT.md`.
It also lacks a packaged update operation that precedes serving with changed application code.

Proposed decision:

- Persist the release that last successfully updated Site data.
- Check compatibility before normal serving begins.
- Apply database updates through an explicit operation with exclusive site access.
- Advance the version record only after successful updates.
- Refuse unsupported downgrade paths.

The existing [dependency and compatibility RFC](../rfc/dependency-updates-and-compatibility.md) already addresses this boundary.
Its proposed behavior should remain distinct from documented current behavior until implemented.

Finding: F04.
Sources: [domain model](../../CONTEXT.md) and [PHP launcher](../../runtime/launch.php), `remainingSteps()`.

## A04: separate command resolution from site mutation

Question: which preparation should an administrative command perform?

The `dr` path shares startup mutations, including generated settings replacement and translation copying.
The native entrypoint, PHP options parser, and development shell entrypoint also repeat parts of the command contract.
MCP client configuration assumes a conventional Composer installation instead of the packaged executable.

Proposed decision:

- Resolve the application, Site data, and command before choosing a mutating operation.
- Keep inspection commands free of startup mutations.
- Store generated connection values separately from persistent user overrides.
- Generate MCP commands through the public executable with an explicit Site data path.
- Make the development entrypoint use the same preparation operation as the packaged application.

This gives each command a defined effect and removes repeated lifecycle assumptions from the development shell script.

Findings: F05, F07, and F16.
Sources: [PHP launcher](../../runtime/launch.php), [entrypoint](../../packaging/entrypoint.go), and [development entrypoint](../../packaging/dev-entry.sh).

## A05: separate addresses, identity, and credentials

Question: what must be established before opening an administrator login link?

The bind address, browser origin, and instance identity have different meanings.
The ownership probe accepts an HTTP status as proof of identity.
URL construction does not handle IPv6 consistently.
Credential values travel through the same environment as ordinary process configuration and survive beyond installation.

Proposed decision:

- Represent the listener and browser origin through one address component with explicit fields.
- Authenticate a serving instance before sending it a login credential.
- Separate listener readiness from successful Drupal bootstrap.
- Limit installation secrets to the process that needs them.
- Define a TLS requirement for automatic administrator login over non-loopback connections.

These boundaries can use small concrete functions and records. They do not require a new dependency-injection layer.

Findings: F01, F02, F09, F12, and F14.
Sources: [PHP launcher](../../runtime/launch.php), [entrypoint](../../packaging/entrypoint.go), and [Caddy configuration](../../runtime/Caddyfile).

## A06: distinguish release identity from filesystem and URL guarantees

Question: which objects are actually immutable or private?

A release-specific directory does not make every URL served from it version-specific.
Replacing a release can change an asset at the same URL despite its immutable cache header.
Similarly, a user-specific directory name does not establish private filesystem permissions.
Windows cache validation currently checks only that a directory exists.

Proposed decision:

- Grant immutable browser caching only to URLs whose version changes with their contents.
- Revalidate stable asset URLs and replaceable image derivatives.
- Validate Windows ownership and ACLs for executable cache locations.
- State separately whether a cache check establishes completeness, integrity, or access control.

Findings: F10 and F11.
Sources: [Caddy configuration](../../runtime/Caddyfile) and [Windows cache root](../../packaging/launcher/internal/runtime/root_windows.go).

## A07: preserve subprocess outcomes at the process boundary

Question: how does a failed Drupal operation reach the site owner?

`runDrush()` discards standard output and standard error, then reports a fixed failure message.
`loginLink()` already captures a diagnostic through a temporary file and reports it when needed.
The serving readiness check accepts any HTTP response, including an application error.

Proposed decision:

- Give subprocess execution a consistent result containing the exit status and a bounded diagnostic.
- Keep ordinary successful startup output brief.
- Preserve the failing operation's explanation in a private log or error report.
- Report readiness only after the expected application response.

Share execution machinery where behavior repeats. Keep each caller responsible for interpreting its operation's result and protecting secrets.

Findings: F09 and F15.
Sources: [PHP launcher](../../runtime/launch.php), `runDrush()` and `loginLink()`, and [entrypoint](../../packaging/entrypoint.go), `openWhenReady()`.

## Proposed order

Question: which decisions should guide the first changes?

1. Authenticate handover and constrain credential lifetime.
2. Protect active release files from cleanup.
3. Make settings publication atomic and administrative inspection read-only.
4. Establish database compatibility checks and explicit updates.
5. Complete the packaged MCP setup.
6. Consolidate startup state and development command behavior.
7. Correct readiness, caching, and address handling.

Each step addresses a demonstrated boundary before any wider restructuring of `launch.php`.
The complete findings document records the conditions under which each defect applies.

## Review limits

Question: what evidence supports these proposals?

- The review traced application and packaging source at the recorded commit.
- A temporary loopback server reproduced the handover identity failure.
- The bundled Composer lock matched the checkout.
- The unpacked seed enabled `mcp_tools` without its STDIO submodule.
- Windows behavior and interrupted installation paths were assessed from source.
- The review did not run a complete deployment on every supported platform.
- Tests and their architecture were excluded.

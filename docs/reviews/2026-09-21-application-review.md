# Complete application review

## Scope

Question: what application behavior was reviewed?

Reviewed on 2026-09-21 at commit `192002efe563f910b8d93f7c4142d366be706c6f`.
The review covered application startup, packaging, persistent Site data, Drupal integration, and bundled MCP behavior.
Tests were excluded. The review changed no application code.

This document records findings and proposed corrective decisions. Recommendations are not accepted implementation decisions.
The [architecture review](2026-09-21-architecture-review.md) groups them by ownership boundary and simplification opportunity.

Source links refer to repository files. Symbol names identify the reviewed code; the recorded commit fixes the review's scope.

## Finding index

Question: which findings need attention?

| ID | Priority | Finding |
|---|---|---|
| F01 | High | Unauthenticated handover |
| F02 | High | Installation credential exposure |
| F03 | High | Active release cleanup |
| F04 | High | Missing release compatibility gate |
| F05 | High | Live settings replacement |
| F06 | Medium | Mixed-release fallback |
| F07 | Medium | Incomplete MCP setup |
| F08 | Medium | Accidental database adoption |
| F09 | Medium | False readiness |
| F10 | Medium | Incorrect immutable caching |
| F11 | Medium, conditional | Unchecked Windows cache privacy |
| F12 | Medium | Inconsistent IPv6 handling |
| F13 | Medium | Missing serving lease |
| F14 | Medium, conditional | Credentials over network HTTP |
| F15 | Medium | Lost installation diagnostics |
| F16 | Medium | Divergent development startup |

## F01: handover accepts an unauthenticated listener

Question: can another listener receive a real administrator login link?

Priority: high. Applies when another program occupies the configured port before an interactive start.

`portOwner()` accepts any HTTP 204 response as proof that the listener serves the selected Site data.
`handOver()` then generates an administrator login link and opens it against that listener.
Another local account can bind an available unprivileged port and receive the credential when the browser opens.

The path hash does not authenticate the listener. The launcher sends it in the probe request.
A temporary server that always returned 204 was classified as `mine` during the review.
The reproduction used no real login credential or Site data.

Proposed correction: authenticate the existing instance through a challenge-response protocol or authenticated local IPC before handing it a credential.

Source: [PHP launcher](../../runtime/launch.php), `portOwner()` and `handOver()`.

## F02: installation credentials escape their required scope

Question: where do installation secrets remain visible?

Priority: high. Exposure through process arguments depends on operating-system process visibility.

`installDrupal()` passes database credentials and the administrator password in subprocess arguments.
Other local users can read those arguments on systems that permit process inspection.

The launcher also exports `DRUPACK_ADMIN_PASSWORD` and retains it when starting the web server.
This includes the generated password on a default first start. PHP code can later read the environment value.

Proposed correction:

- Pass installation secrets through a restricted channel rather than subprocess arguments.
- Limit each secret to the process that needs it.
- Remove administrator credentials before starting the web server.

Source: [PHP launcher](../../runtime/launch.php), `installDrupal()`, the environment export loop, and `replaceProcess()`.
Evidence: source tracing; no credentials were collected.

## F03: cleanup can remove active release files

Question: does cleanup know whether a process still uses an entry?

Priority: high.

`CleanApps()` removes application directories without checking for active servers.
Automatic application sweeping uses the time of the last start. A server running beyond 30 days does not refresh that timestamp.
Starting another release can therefore remove an application that remains in use.

Runtime cleanup removes older entries immediately after a new runtime is staged.
On Unix, a process can survive executable deletion, but later subprocess launches and CA-file reads can fail.
Application deletion also removes PHP files and static assets required by later requests.

Proposed correction: hold release usage locks for each process's lifetime and make every cleanup path skip active entries.

Sources: [application cache](../../packaging/launcher/internal/runtime/app.go), `CleanApps()` and `sweepApps()`; [runtime cache](../../packaging/launcher/internal/runtime/cache.go), `removeOthers()`.
Evidence: source tracing; destructive cleanup was not performed against a running site.

## F04: existing Site data has no release compatibility gate

Question: what prevents incompatible code from opening an existing database?

Priority: high.

An installation marker makes `remainingSteps()` return without checking release compatibility.
Startup neither applies pending database updates nor compares a persisted release version.
Newer code can therefore open an older schema. Older executables can also open databases updated by newer releases.

`CONTEXT.md` describes a version record and downgrade refusal that the implementation does not contain.
The dependency and compatibility RFC identifies these as proposed behavior.
Drupal's [update procedure](https://www.drupal.org/docs/updating-drupal/updating-drupal-core-via-composer) requires database updates after relevant code updates.

Proposed correction: implement a version record and an explicit update operation before treating executable replacement as a complete upgrade workflow.

Sources: [PHP launcher](../../runtime/launch.php), `remainingSteps()`; [domain model](../../CONTEXT.md); [compatibility RFC](../rfc/dependency-updates-and-compatibility.md).
Evidence: source and documentation review; no database upgrade or downgrade was attempted.

## F05: administrative commands rewrite live settings

Question: can an inspection command disturb a serving site?

Priority: high.

The shared startup path rewrites `settings.php` even for `dr status`.
Established sites take no startup lock.
`writeSettings()` truncates the existing file and writes its replacement under `LOCK_EX`.
PHP's `require` does not acquire that lock, so a concurrent request can encounter empty or incomplete settings.

The replacement also discards custom settings.
Examples include proxy configuration and database options that `recordedOptions()` does not preserve.

Proposed correction:

- Separate generated connection data from persistent user overrides.
- Keep inspection commands free of startup mutations.
- Publish generated files through atomic replacement when their contents change.

Source: [PHP launcher](../../runtime/launch.php), `writeSettings()`, `recordedOptions()`, and the shared startup path.
Evidence: source tracing; the concurrent-read failure was not reproduced.

## F06: fallback can mix different releases

Question: does fallback preserve the release's component pairing?

Priority: medium. Applies when runtime extraction or cache locking fails and an older cached runtime remains available.

`Prepare()` can return that older runtime.
The launcher then calls `PrepareApp()` with the current embedded application and launches it with the returned executable.
The pair can disagree about PHP requirements, extensions, or the process environment contract.

Checksum verification establishes that the fallback matches its own manifest. It does not establish compatibility with the current application.

Proposed correction: select a complete compatible release pair or stop with the extraction error.

Sources: [launcher](../../packaging/launcher/main.go), `run()`; [runtime cache](../../packaging/launcher/internal/runtime/cache.go), `fallbackOrFail()` and `activeFallback()`.
Evidence: source tracing; a mixed-release deployment was not started.

## F07: packaged MCP setup is incomplete

Question: can the documented client configuration start the packaged MCP server?

Priority: medium.

The build and server-database initialization enable `mcp_tools` alone.
The `mcp-tools:serve` command belongs to `mcp_tools_stdio`, a separate submodule.
Inspection of the unpacked seed confirmed that only the base MCP module was enabled.

The bundled `ClientConfigGenerator::buildBareMetalConfig()` produces `<Drupal web root>/vendor/bin/drush`.
That path does not exist in the application archive.
The configuration also lacks Drupack's bundled PHP invocation and an explicit Site data selection.

Proposed correction: enable the intended transport and tool modules, then generate configuration around the public executable and selected Site data.
The command should use `dr --data-dir ABSOLUTE_PATH mcp-tools:serve` rather than a cache-internal Drush path.

Sources: [Dockerfile](../../Dockerfile), seed construction; [PHP launcher](../../runtime/launch.php), `runStep()`.
Bundled evidence: `mcp_tools/src/Service/ClientConfigGenerator.php` and `mcp_tools_stdio/src/Commands/McpToolsStdioCommands.php` within the application archive.

## F08: interrupted database installation can become adoption

Question: can recovery distinguish an owned installation from a foreign database?

Priority: medium. Applies to MySQL and PostgreSQL initialization.

`installSite()` treats a bootstrappable database as adopted while `first-install` exists.
That marker remains until initialization finishes.
If installation completes before progress is recorded, interruption leaves a database that the next start treats as adopted.
The modules step then skips MCP enablement and removal of automatic-update modules.

A partial installation can instead leave tables that trigger the refusal to install into an occupied database.
Recovery therefore covers only some interruption points.

Proposed correction: persist database ownership independently of step completion and define recovery for an owned incomplete installation.

Source: [PHP launcher](../../runtime/launch.php), `remainingSteps()`, `installSite()`, `runStep()`, and `initialize()`.
Evidence: source tracing; the interruption windows were not reproduced against a database server.

## F09: readiness accepts HTTP error responses

Question: does the readiness message establish that Drupal works?

Priority: medium.

`openWhenReady()` treats any HTTP response as success.
An HTTP 500 response from Drupal or an HTTP 400 host rejection produces the readiness message.
The browser can then open despite a failed bootstrap.

Proposed correction: require an expected healthy response and report unexpected status codes.
Keep listener availability separate from Drupal readiness.

Source: [native entrypoint](../../packaging/entrypoint.go), `openWhenReady()`.
Evidence: source tracing of the success condition.

## F10: immutable caching covers mutable URLs

Question: do immutable cache headers require version-specific content?

Priority: medium.

The Caddy asset matcher assigns a one-year immutable lifetime to matching files beneath core, modules, themes, and libraries.
It does not require a version-specific URL.
Replacing the executable can change a file at the same URL while a browser retains the previous content.

The image-style matcher also treats any matching URL with `itok` as immutable.
An authorization token does not establish that the image contents never change.

Proposed correction: reserve immutable caching for content-versioned URLs and revalidate stable paths or replaceable derivatives.

Source: [Caddy configuration](../../runtime/Caddyfile), `versionedAssetPath`, `versionedAssetQuery`, and their cache headers.
Evidence: configuration review; browser cache behavior across an upgrade was not reproduced.

## F11: Windows cache privacy is unchecked

Question: can another account modify code that the launcher executes?

Priority: medium, conditional on a cache location writable by another account.

Windows `privateRoot()` checks only that the path is a directory.
The same check applies to explicit cache locations and temporary-directory fallback.
A SID in a directory name prevents naming collisions but does not enforce access control.

Warm runtime validation checks file sizes. Application validation checks a completion marker.
Neither compensates for a cache location that another account can modify.
The default private profile path reduces exposure; custom locations need their own permission checks.

Proposed correction: validate ownership and ACLs before executing from explicit or fallback cache locations.

Sources: [Windows cache root](../../packaging/launcher/internal/runtime/root_windows.go), `privateRoot()`; [runtime cache](../../packaging/launcher/internal/runtime/cache.go), `warm()`; [application cache](../../packaging/launcher/internal/runtime/app.go), `complete()`.
Evidence: source review. This finding was not reproduced on Windows.

## F12: IPv6 address handling is inconsistent

Question: do accepted IPv6 options produce valid addresses everywhere?

Priority: medium.

Host validation accepts IPv6 addresses, but the browser URL interpolates the host without brackets.
For example, `--host ::1` produces an invalid HTTP authority.
The ownership probe also substitutes IPv4 loopback for the IPv6 wildcard address `::`.
That probe cannot reliably describe an IPv6-only listener.

Proposed correction: centralize URL authority formatting and preserve the listener's address family during local probes.

Source: [PHP launcher](../../runtime/launch.php), host validation, `$url` construction, and `portOwner()`.
Evidence: source tracing; no IPv6 deployment was started.

## F13: established sites have no serving lease

Question: what prevents two releases from serving the same Site data?

Priority: medium.

The startup lock is acquired only when initialization steps remain and is released before serving.
Ownership checks examine the selected port.
Two starts that select different ports can therefore serve the same established Site data, including through different releases.
They also overwrite the shared listener record used by later Drush commands.

Proposed correction: acquire a site serving lease independently of the network address.
Define concurrent administrative operations separately from concurrent servers.

Source: [PHP launcher](../../runtime/launch.php), `startupLock()`, `writeListener()`, and the main startup path.
Evidence: source tracing; concurrent cross-release serving was not attempted.

## F14: network serving exposes administrator credentials over HTTP

Question: what protects administrator login outside loopback?

Priority: medium, conditional on non-loopback access without a separate TLS boundary.

The CLI accepts non-loopback listeners and a browser host.
Caddy serves HTTP with automatic HTTPS disabled, and generated browser URLs use `http://`.
Administrator login links and subsequent session traffic therefore traverse the network without TLS in a direct remote deployment.

The default loopback listener limits this exposure for ordinary local use.

Proposed correction: define the supported TLS deployment contract before automatically opening administrator credentials over a non-loopback connection.

Sources: [Caddy configuration](../../runtime/Caddyfile), global options and site address; [PHP launcher](../../runtime/launch.php), `$url` construction.
Evidence: configuration and source review; no network credentials were transmitted during review.

## F15: installation failures lose subprocess diagnostics

Question: can the user identify why an installation step failed?

Priority: medium.

`runDrush()` sends standard output and standard error to the null device.
It reports only the caller's fixed failure message when the process exits unsuccessfully.
Database failures and recipe errors therefore lose their underlying explanation.

`loginLink()` already captures a subprocess diagnostic for failed login-link generation.

Proposed correction: preserve a bounded diagnostic in a private log or error report and identify the failed operation.
Keep secret values out of ordinary terminal output.

Source: [PHP launcher](../../runtime/launch.php), `runDrush()` and `loginLink()`.
Evidence: subprocess descriptor review.

## F16: development startup diverges from packaged startup

Question: does the development entrypoint perform the same preparation as the release?

Priority: medium.

`dev-entry.sh` invokes the PHP launcher in Drush mode and assumes that this initializes a fresh site.
The current Drush branch rejects Site data without settings, so an empty development directory cannot follow that path successfully.

The script exports its server environment manually and omits the runtime identity.
It also refreshes selected top-level files without copying updated support classes.
Changes to those classes can remain absent from the development application copy.

Proposed correction: share an explicit preparation operation with packaged startup and refresh the complete runtime support layer.

Sources: [development entrypoint](../../packaging/dev-entry.sh), its copy loop and Drush invocation; [PHP launcher](../../runtime/launch.php), the Drush branch.
Evidence: source tracing; the development container was not started.

## Architecture findings

Question: which ownership changes connect the defects?

| Proposed boundary | Related findings | Decision developed in the architecture review |
|---|---|---|
| Complete release ownership | F03, F06 | Pair runtime and application identity with usage locks |
| Site data state ownership | F05, F08, F13 | Centralize transitions and separate installation ownership from progress |
| Database compatibility | F04 | Gate serving on a persisted compatible release |
| Command effects | F05, F07, F16 | Separate resolution from mutation and share packaged invocation rules |
| Address and credential handling | F01, F02, F09, F12, F14 | Separate identity, origins, readiness, and secret lifetime |
| Immutability and permissions | F10, F11 | Verify URL versions and executable-cache access independently |
| Subprocess outcomes | F09, F15 | Preserve failures and require application readiness |

The [architecture document](2026-09-21-architecture-review.md) records the observed structure and proposed decisions for each boundary.

## Existing protections

Question: which protections were present in the reviewed implementation?

- The default listener binds to loopback.
- Drupal receives explicit trusted-host patterns.
- Caddy administration is disabled.
- Public-storage PHP execution has dedicated blocking rules.
- Archive extraction rejects unsafe paths and unsupported entry types.
- The Unix runtime cache checks private ownership and permissions.
- Application extraction uses a staging directory before publication.
- Site data is separate from the shared application directory.

These observations do not establish that every protection withstands all platform-specific bypasses.

## Dependency advisory check

Question: what did the production Composer advisory check report?

The review ran `composer --no-cache --no-plugins --no-scripts audit --locked --no-dev --format=json` against `drupal/composer.lock`.
It reported no unignored advisories and no abandoned packages.

One advisory was explicitly ignored for `mcp/sdk` version `v0.6.0`:

- Identifier: `PKSA-p9gd-j6gr-6f9t`, also `CVE-2026-53965`.
- Affected behavior: client HTTP transport buffers an unterminated server-sent event without a bound.
- Recorded exclusion reason: the bundled Drupal integrations use the SDK server API.
- Upstream details: [PHP SDK advisory](https://github.com/modelcontextprotocol/php-sdk/security/advisories/GHSA-7m52-jw36-44r3).

The audit did not cover native PHP, Caddy, FrankenPHP, or operating-system libraries.
The application archive's Composer lock matched the checkout during inspection.

## Validation limits

Question: which findings were reproduced and which were inferred from source?

- F01's identity failure was reproduced with a temporary loopback HTTP server.
- F07's missing Drush path was checked in the application archive.
- F07's enabled MCP modules were checked in an unpacked seed opened read-only.
- Other findings came from application source, configuration, and process-flow analysis.
- Windows behavior was not exercised on a running Windows deployment.
- Database interruption windows and cross-release serving were not reproduced.
- No full deployment matrix or exhaustive upstream dependency review was performed.
- Tests were excluded from the review.

The findings describe the recorded revision. Later fixes require separate verification.

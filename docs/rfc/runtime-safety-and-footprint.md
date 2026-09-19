# Runtime safety and footprint

Proposed on 2026-09-17. Revised after the second audit on the same date. Implementation remains pending.

## Question

How should Drupack protect writable storage, recover interrupted initialization, coordinate concurrent starts, and reduce runtime cost?

## Audit evidence and limits

- The HTTP probe used the current Caddy configuration with a harmless PHP fixture and the available FrankenPHP binary.
- Initialization probes ran the current launcher against temporary directories with controlled subprocess failures.
- The available uncompressed artifact contains an older launcher. Its integration results do not validate the current checkout.
- Windows activation races were identified from source. They were not reproduced on Windows.
- Existing extraction and extension-size measurements were reviewed. No new throughput, request-latency, or peak-memory benchmark was run.

## PHP execution in public storage

High priority. [The upload-path matcher](../../runtime/Caddyfile) blocks paths ending in `.php`. FrankenPHP also accepts a PATH_INFO suffix after the script name.

The probe returned 404 for `/sites/default/files/probe.php` and executed the fixture through `/sites/default/files/probe.php/path-info`, with HTTP 200.

Exploitation requires a PHP file in public storage. The probe establishes a server execution-barrier bypass; it does not establish an unrestricted Drupal upload vulnerability.

Block PHP execution throughout writable public storage, including PATH_INFO variants. Preserve this boundary when shared extraction changes public-file routing.

Acceptance checks:

- Requests for PHP fixtures in public storage fail both with and without PATH_INFO.
- Encoded-path and platform case variants cannot bypass the barrier.
- Ordinary public files and Drupal image derivatives remain accessible.

## Recoverable initialization

High priority. [The launcher](../../runtime/launch.php) writes `settings.php` before replacing SQLite administrator credentials. Later starts treat that file as successful initialization.

A forced administrator subprocess failure left settings present. A second invocation reached server dispatch without credentials or another administrator update. The bundled seed has a known password.

Server-database installation has a separate completion problem. It writes `site-installed` only after installation and MCP enablement succeed. A failure between those steps leaves installation eligible to repeat.

An explicit server-database retry can repeat `site:install --yes` against an installed database. A restart without backend options defaults to SQLite and skips unfinished provisioning.

Persist initialization progress separately from connection settings. Record completion only after every required step succeeds. Recover from the failed step without automatically reinstalling an existing database.

Acceptance checks:

- Failed credential replacement prevents HTTP startup on subsequent launches.
- Completed settings determine the backend when later commands omit database options.
- Failure after database installation never authorizes an automatic reinstall.
- Recovery preserves existing content and finishes required module enablement.

## Existing-site compatibility

Existing SQLite sites lack the proposed completion state. Some can be valid sites; others can retain interrupted setup. File presence alone cannot distinguish them.

Define an explicit adoption procedure before implementation. It must verify installed state and require administrator recovery where credential replacement cannot be established.

The procedure must preserve database contents and the hash salt. It must never copy a seed over an existing database or infer permission to reinstall it.

## Startup lock

Medium priority. The current initialization check and subsequent writes have no shared lock. Individual file writes with `LOCK_EX` do not protect the sequence.

Acquire an initialization lock for the canonical Site data path, then read initialization state under that lock. Release it after initialization completes.

The earlier lifetime-lock proposal would block normal Drush access while the server runs. Any server-instance restriction needs separate ownership and must permit `dr` commands.

Acceptance checks:

- Two first starts against one empty directory perform initialization exactly once.
- A competing process waits or fails clearly before it changes site contents.
- Equivalent paths to one directory resolve to the same lock.
- Drush remains usable while the web server runs.

## Windows runtime activation

Medium priority. Windows ran its own launcher, which shared one `active.pending` filename between processes, so concurrent launches could overwrite or rename each other's pending activation. Extraction also renamed an existing runtime target without synchronization.

All three platforms now run [the shared launcher](../../packaging/launcher), which stages and activates under the cache root's lock and rechecks the target once it holds that lock.

Acceptance checks:

- Simultaneous starts of one release activate one complete runtime.
- Simultaneous starts of different releases cannot exchange pending activation contents.
- A failed extraction preserves a usable previous runtime.
- Interrupted activation leaves a recoverable cache state.

## Public asset caching

Medium priority. [The static-file rule](../../runtime/Caddyfile) applies a one-year immutable cache policy to matching files, including uploaded images and PDFs.

Replacing an upload at the same URL can leave browsers displaying its previous contents for that period. Restrict immutable caching to assets with versioned URLs.

Acceptance checks:

- Mutable uploads receive a policy that permits revalidation.
- Replacing an upload at one URL becomes visible under that policy.
- Versioned application assets retain long-lived caching.

## Network transport

The packaged listener stays loopback-only by default. Explicit network mode currently serves plain HTTP. A warning or acknowledgement cannot protect traffic.

Document TLS termination for remote administration across untrusted networks. Define trusted proxy addresses and forwarded-header handling so Drupal recognizes HTTPS correctly. Trusted host patterns remain enabled.

An HTTPS listener may follow when certificate loading, renewal ownership, and platform storage have a defined design.

Acceptance checks:

- Default startup accepts requests only through loopback.
- Drupal recognizes the HTTPS origin behind the documented proxy configuration.
- Direct clients cannot forge trusted proxy headers.
- Documentation states the network listener's HTTP behavior and the proxy trust boundary.

## Shared application extraction

Propose one immutable application extraction per release under the user cache. Site data retains mutable files, configuration, and database state.

[ADR 0002](../adr/0002-shared-application-extraction.md) records Windows extraction at 18.7 seconds and warm startup at 3.7 seconds. These historical measurements were not repeated in this audit.

The extractor validates the bundled archive identity. It writes a completion marker after all files are present. A later process uses the completed release directory without reading or extracting the archive.

The application directory is read-only after extraction. The packaged settings proxy reads Site data through `DRUPACK_RUNTIME_DATA_DIR`. Caddy maps public files from Site data.

Acceptance checks:

- Two new sites from one release share one application extraction.
- A second startup avoids archive extraction and the restart process.
- Replacing the executable keeps Site data intact and selects its new release directory.
- A partial extraction never becomes active.
- Concurrent extraction cannot publish an incomplete release directory.
- Two sites can serve different uploads concurrently through the shared application.

## PHP extension scope

Treat extension reduction as a measured optimization. [Earlier build experiments](../plans/portable-drupal.md) recorded roughly 5–6% compressed-size savings. They do not establish savings for the current release.

Define an extension allowlist for supported bundled functionality, including modules users can enable later. Record consumers and required capabilities for each extension.

The Linux, macOS, and Windows builders derive their extension lists from that allowlist. Platform-specific extensions require a documented reason.

Removing an extension requires a package-install check and a runtime capability check. The test suite verifies the SQLite, MySQL, and PostgreSQL drivers on every platform that claims support.

Acceptance checks:

- The allowlist records every shipped extension and its consumer.
- A build fails when a required extension is absent.
- The release artifact size is recorded before and after the reduction.
- Each platform verifies every advertised database driver.

## Delivery order

1. Close the PHP execution bypass and add path-variant tests.
2. Define initialization recovery and existing-site adoption, with an initialization lock.
3. Serialize Windows activation and correct mutable-asset caching.
4. Verify and document TLS proxy behavior.
5. Implement shared extraction with concurrent-start and replacement tests.
6. Measure extension reduction against the new extraction baseline.

## Correction to the dependency finding

Composer audit reported no unsuppressed advisories and one documented MCP SDK exception. The installed version falls within the affected range.

The [upstream advisory](https://github.com/modelcontextprotocol/php-sdk/security/advisories/GHSA-7m52-jw36-44r3) concerns the SDK's HTTP client connected to a malicious server. Enabling MCP Tools does not establish that client path's reachability.

The first audit's high-severity application-exposure claim was unsupported. Reassess the exception when dependencies or MCP client usage change.

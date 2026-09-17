# Runtime safety and footprint

Proposed on 2026-09-17.

## Question

How should Drupack prevent concurrent first-start writes, protect network traffic, remove repeated application extraction, and reduce the PHP runtime scope?

## Startup lock

Each Site data directory must have one startup lock. The launcher acquires it before creating persistent directories or checking installation state. It holds the lock until FrankenPHP exits.

The lock prevents concurrent Seed copies, concurrent server-database installations, and two SQLite servers against one database. A later process fails with a clear message that names the Site data directory.

Acceptance checks:

- Two first starts against one empty directory produce one successful process.
- The second process writes no database, settings, files, or installation marker.
- A second start after installation cannot open the same SQLite Site data directory.

## Network transport

The packaged listener stays loopback-only by default. A non-loopback listener requires an explicit acknowledgement that the connection is HTTP.

The documentation must require a TLS reverse proxy for untrusted networks. The proxy terminates HTTPS and forwards only to the selected Drupack listener. Trusted host patterns remain enabled.

An HTTPS listener may follow when certificate loading, renewal ownership, and platform storage have a defined design.

Acceptance checks:

- Default startup accepts requests only through loopback.
- Non-loopback startup prints the HTTP transport warning.
- Documentation includes a reverse-proxy example and its trust boundary.

## Shared application extraction

The executable extracts its immutable application once per release under the user cache. Site data keeps only mutable files, configuration, and database state.

The extractor validates the bundled archive identity. It writes a completion marker after all files are present. A later process uses the completed release directory without reading or extracting the archive.

The application directory is read-only after extraction. The packaged settings proxy reads Site data through `DRUPACK_RUNTIME_DATA_DIR`. Caddy maps public files from Site data.

Acceptance checks:

- Two new sites from one release share one application extraction.
- A second startup avoids archive extraction and the restart process.
- Replacing the executable keeps Site data intact and selects its new release directory.
- A partial extraction never becomes active.

## PHP extension scope

The build defines an allowlist of PHP extensions required by Drupal core, enabled modules, Drush, and the supported database drivers. Each extension has a named consumer and a test.

The Linux, macOS, and Windows builders derive their extension lists from that allowlist. Platform-specific extensions require a documented reason.

Removing an extension requires a package-install check and a runtime capability check. The test suite verifies the SQLite, MySQL, and PostgreSQL drivers on every platform that claims support.

Acceptance checks:

- The allowlist records every shipped extension and its consumer.
- A build fails when a required extension is absent.
- The release artifact size is recorded before and after the reduction.
- Each platform verifies every advertised database driver.

## Delivery order

Implement the startup lock first. It protects existing Site data without changing application packaging. Add the network warning next. Then implement shared extraction and its replacement tests. Reduce the extension set after the shared-extraction baseline is measured.

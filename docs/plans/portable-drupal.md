# Plan: Portable Drupal CMS

> Source PRD: [Portable Drupal CMS](../prd/portable-drupal.md)

## Architectural decisions

- Distribute one GNU FrankenPHP executable for Linux x86-64; the host supplies compatible `glibc` and a browser.
- Embed Drupal CMS, Byte, PHP, Caddy, required extensions, application dependencies, and available translations.
- Use Docker and internet access during builds; installation and normal local use must work offline.
- Keep application code fixed within each release. Adding code requires a rebuild.
- Use SQLite and Drupal's existing schema, authentication, permissions, and installation workflow.
- Byte is the sole site template. Drupal owns content models and language workflows.
- Persist site data independently of the executable, defaulting to `./data` relative to the launch directory.
- Support `--data-dir` for another location. Keep database and private files inaccessible through HTTP.
- Default to loopback access; access from other devices requires explicit configuration.

### Module boundaries and exclusions

Build and packaging produces the executable from pinned inputs.
Startup and site storage resolves persistent paths and starts Drupal through FrankenPHP.
Use existing upstream capabilities within these boundaries.

Email configuration and upgrade commands remain out of scope.
Executable replacement must preserve data; compatibility between application versions is not promised.
Language selection remains under Drupal's control, with no custom installer or automatic installation of every bundled language.

### Verification and sequencing

Complete phases in the numbered order. Each phase extends the executable and includes tests of its observable behavior.
Offline tests disable outbound network access while retaining browser access to the local server.
Test the built artifact without separately installed PHP, Composer, a web server, or a database server.

Phase 1 resolves version compatibility, the supported Linux baseline, and the launch interface.
Private-file protection begins with the initial installation and receives broader coverage in Phase 2.
Phases 1–6 passed their acceptance checks on 15 September 2026.
The full offline browser suite covers English and French installation.
It also verifies content translation and Arabic editing.
Separate checks cover remote authentication and a rebuilt artifact with an additional module.
Phase 7 passed its acceptance checks with development-file exclusions and executable compression.

## Phase 1: Install Byte offline in English

User stories: 1–4, 6–11, 22–23.

### What to build

Build the executable and complete a fresh Byte installation through Drupal's browser interface with network access disabled.
The user supplies site details and administrator credentials, then reaches the installed site backed by SQLite.
Use the default persistent data directory and loopback access.

Resolve compatible pinned dependencies, required extensions, embedded-file extraction behavior, and writable installation paths through this working path.
Define and document the launch command, default port, supported Linux distribution, and minimum `glibc` version.

### Acceptance criteria

- [x] Docker produces the GNU Linux x86-64 executable from pinned dependencies.
- [x] The executable starts on the documented baseline without separately installed application runtime tools.
- [x] A fresh launch presents Drupal CMS's installer with Byte as the sole template.
- [x] Installation completes in English with outbound network access disabled.
- [x] The supplied credentials permit administrator login after installation.
- [x] Byte's required assets load from the local package.
- [x] The SQLite database and persistent installation settings use the default data directory.
- [x] Default access is limited to loopback, and direct HTTP requests cannot retrieve database or private files.

## Phase 2: Preserve and isolate site data

User stories: 12–15, 20, 25.

### What to build

Create content and upload files through the installed site, then restart the executable and continue work.
Repeat the installation with a custom data directory.
Keep persistent writes within the selected site storage and verify HTTP access controls for private data.

### Acceptance criteria

- [x] Content and uploads created offline remain available after process restart.
- [x] Subsequent launches reuse the installed site and its administrator credentials.
- [x] The data-directory option supports a fresh installation and subsequent reuse.
- [x] Separate selected data directories retain independent site content and settings.
- [x] Tests confirm persistent writable paths use the selected site storage.
- [x] HTTP tests cover database files and private site files without exposing their contents.
- [x] Replacing the executable with the same build preserves the installed site.

## Phase 3: Use interface languages offline

User stories: 16–17, 19.

### What to build

Bundle available interface translation resources for French, Simplified Chinese, Spanish, Hindi, and Arabic alongside the English interface.
Exercise Drupal's normal installation language selection and later language management without network access.
Verify Arabic administration with right-to-left layout.

### Acceptance criteria

- [x] All six agreed languages are available through Drupal's standard language workflow while offline.
- [x] Simplified Chinese uses the agreed `zh-hans` locale.
- [x] A fresh installation can use an available non-English language through Drupal's normal installer.
- [x] Users can add another bundled language after installation without a required download.
- [x] Only languages selected through Drupal are installed; bundling does not activate all six.
- [x] Language settings persist across restart.
- [x] Arabic administration and core editing interactions remain usable with right-to-left layout.
- [x] Upstream translation gaps are recorded without claiming complete translation of every string.

## Phase 4: Translate content offline

User story: 18.

### What to build

Use Drupal's existing content translation workflow with Byte and the bundled languages.
An editor enables translation where needed, creates content, adds a translation, and retrieves both language versions while offline.

### Acceptance criteria

- [x] Drupal's standard interfaces expose content translation for a supported Byte content type.
- [x] An editor can create original content and a translation with network access disabled.
- [x] Both language versions can be retrieved through Drupal's normal site navigation or language routes.
- [x] Translated content and its settings survive process restart.
- [x] The workflow uses Drupal's existing permissions and interfaces.

## Phase 5: Enable network access explicitly

User stories: 4–5, 15.

### What to build

Provide a documented configuration path for access from another device.
Start an installed site with that configuration and exercise its browser interface remotely.
Retain loopback access as the default and preserve private-data protections in both configurations.

### Acceptance criteria

- [x] An unmodified launch remains inaccessible through non-loopback interfaces.
- [x] Explicit configuration permits a second device or isolated test client to access the site.
- [x] The remotely accessed site uses the same persisted installation and Drupal authentication.
- [x] Database and private-file HTTP protections pass under the network-access configuration.
- [x] The documented configuration covers the intended listener and Drupal host handling.

## Phase 6: Verify fixed-bundle customization

User stories: 21–24.

### What to build

Enable eligible bundled modules and themes through Drupal and verify that the changes persist.
Then add a compatible component to the build inputs, rebuild the executable, and verify it through a fresh offline installation.
This demonstrates the supported path for application code additions without introducing runtime downloads or upgrades.

### Acceptance criteria

- [x] An eligible bundled module can be enabled and its behavior exercised offline.
- [x] An eligible bundled theme can be activated and rendered offline.
- [x] Activation settings survive restart.
- [x] A rebuilt executable includes an additional pinned component and supports a fresh offline installation.
- [x] The new component can be exercised without runtime package downloads.
- [x] The final artifact passes the earlier installation, persistence, language, content translation, and access-control checks.
- [x] Release instructions describe the supported platform, startup, data storage, and rebuild workflow.

## Phase 7: Reduce executable size

User stories: 1–3, 10, 16, 22–24. Follow-up requested after the initial implementation.

### What to build

Produce a smaller executable with the same supported site behavior.
Measure the current executable, embedded application, and runtime before changing the build.
Evaluate application trimming and executable compression first, then a PHP build containing only required extensions.

Exclude unused test fixtures, development documentation, and source maps from the embedded application where runtime use permits.
Preserve license notices and all resources required for offline installation and normal use.
Evaluate UPX compression and record its effect on startup and memory use.
Keep only changes that pass the acceptance suite.

### Acceptance criteria

- [x] Record baseline executable size, extracted application size, startup time, and memory use under a stated workload.
- [x] Measure each candidate separately so its savings and costs are attributable.
- [x] Audit required PHP extensions before removing any from the runtime.
- [x] The selected executable is smaller than the baseline; report the byte and percentage reduction.
- [x] The GNU Linux target and host runtime requirements remain unchanged.
- [x] Offline Byte installation and all six interface languages remain available through Drupal's standard workflows.
- [x] Content translation, uploads, persistence, and access-control checks pass with the smaller executable.
- [x] Document the selected build options and measured startup or memory tradeoffs.

### Selected build

The build retains Composer's `--no-dev --prefer-dist` installation and the pinned GNU runtime.
It excludes audited development files from the embedded archive:

- Dependency test directories and CI metadata.
- JavaScript source maps and compiled CSS inputs.
- Audited frontend development sources and test videos.
- Node dependency lockfiles and development setup scripts.

Runtime PHP and compiled browser assets remain available.
The archive retains the hyperscriptify MIT notice with executable directory permissions for non-root extraction.
UPX 5.2.1 compresses the executable at level 9; its download is checksum-pinned and the build verifies compressed integrity.
The `uncompressed` Docker target exports the same application without UPX.

### Size and runtime measurements

The final executable saves 362,799,088 bytes, a 76.95% reduction.

| Candidate | Executable bytes | First installer response, seconds | Process RSS, MiB |
| --- | ---: | ---: | ---: |
| Original | 471,482,520 | 3.823 | 420.0 |
| Original with UPX level 1 | 196,142,608 | 6.620 | 584.5 |
| Original with UPX level 9 | 141,509,048 | 5.146 | 584.8 |
| Trimmed, uncompressed | 362,057,880 | 2.254 | 342.5 |
| Trimmed with UPX level 1 | 153,203,620 | 5.348 | 477.1 |
| Trimmed with UPX level 9 | 108,683,432 | 3.968 | 475.6 |

#### Measurement method

Measurements are medians of three fresh-site runs in the existing Selenium Chromium container, with networking disabled.
Each run requests `/core/install.php` until HTTP 200, then requests that page ten more times.
RSS comes from the server's `/proc/PID/status` after those requests.
Host filesystem caches were not cleared; other build and test workloads ran concurrently.
These measurements describe the installer workload, not production throughput or peak memory across re-execution.
The selected build used about 56 MiB more RSS than the original under this workload.
Its compression step took 188 seconds; level 1 took about two seconds.

### Component sizes and runtime scope

| Component | Original bytes | Trimmed bytes |
| --- | ---: | ---: |
| Extracted regular application files | 262,708,861 | 170,389,206 |
| Embedded application tar | 303,011,840 | 193,587,200 |
| Runtime and executable overhead, excluding tar | 168,470,680 | 168,470,680 |

The builder's separate runtime executable is 166,501,000 bytes.
Static library inspection identified image codecs and additional database backends as candidates for a later custom runtime build.
Library archive sizes do not establish linked or compressed savings.
This release removes no PHP extensions and preserves the tested GNU target.
The audit retains SQLite support and `pcntl` for startup.
It also retains GD for images and internationalization support for the agreed language workflows.

#### Application breakdown

These categories contain mutually exclusive regular files, before executable compression.

| Application component | Bytes |
| --- | ---: |
| Drupal core | 43,527,583 |
| Canvas compiled browser UI | 34,892,324 |
| Remaining Canvas files | 7,069,221 |
| Other contributed modules | 34,980,224 |
| PHP vendor dependencies | 15,522,269 |
| Browser libraries | 10,284,784 |
| Byte recipes and initial content | 9,948,164 |
| Translation resources | 7,020,282 |
| Themes | 5,311,955 |
| Other web and root files | 1,832,400 |

Canvas's two largest WASM files total 29,720,902 bytes and remain part of its editing interface.
The tar adds 23,197,994 bytes of headers and padding before compression.
Individual component savings cannot be inferred from the final executable's overall compression ratio.

### Existing-site compatibility

Drupal caches absolute application paths.
The extraction-directory identifier comes from the full application inputs before development-file exclusions.
Compression and trimming therefore retain the original directory identifier when application inputs are unchanged.
A copied baseline site passed authentication and existing-content checks without clearing its caches.
The test preserved its original absolute data-directory path.
This establishes compatibility for this packaging change; application-version upgrades remain outside scope.

### Final verification

The final compressed artifact passed the complete offline browser suite in 179.426 seconds.
Permanent assertions verify development-file exclusions and retained runtime assets, including readable license notices and all 296 translation files.
The installed-site network suite passed on the Debian 12 baseline.
The separate existing-site check passed with unchanged caches and the original data-directory path.
The user's working data directory was not modified.

Artifact SHA-256:

```text
761d8cc3024f24a03e75538daa50995c38c2cc1a10935d3dedbe4eefd8560708
```

## Phase 8: Compare reduced GNU and musl runtimes

### Build scope

Compare the same embedded application with reduced PHP extension sets on GNU and musl.
Keep the Phase 7 executable as the default artifact.
Retain MySQL drivers at the user's request; SQLite remains the configured site database.

Both builds use PHP 8.5.10 with thread safety and OPcache enabled, with JIT disabled.
The pinned builders contain SPC 2.8.6 and cached native libraries.
The reduced builds reuse those libraries, including curl and ZIP dependencies.
They do not rebuild every native library with fewer features.
Fresh source downloads require network access and remain subject to upstream availability and API limits.

Both candidates copy the same application archive and extraction identifier from the default build stage.
The archive contains 193,587,200 bytes; extracted regular files contain 170,389,206 bytes.
The GNU candidate retains the Debian 12 baseline.
ELF inspection found no interpreter or shared-library dependencies in the musl candidate.

### Acceptance criteria

- [x] Build GNU and musl candidates with identical application inputs and PHP extension selections.
- [x] Verify MySQL and SQLite PDO drivers in both executables.
- [x] Measure raw and compressed executable sizes separately.
- [x] Verify final compressed candidates through the complete offline browser suite.
- [x] Verify final compressed candidates against an existing site without clearing caches.
- [x] Record startup and memory measurements for both compressed candidates.
- [x] Preserve the working executable and the user's data directory.

### Size measurements

| Runtime | Uncompressed bytes | UPX level 9 bytes |
| --- | ---: | ---: |
| Phase 7 GNU | 362,057,880 | 108,683,432 |
| Reduced GNU | 342,930,712 | 101,707,036 |
| Reduced musl | 344,955,472 | 103,084,852 |

Reduced GNU saves 6,976,396 compressed bytes versus Phase 7, or 6.42%.
Reduced musl saves 5,598,580 compressed bytes versus Phase 7, or 5.15%.
Musl is 1,377,816 compressed bytes larger than reduced GNU.
Both compressed artifacts passed UPX integrity checks.

### Installer measurements

| Runtime | First installer response, seconds | Process RSS, MiB |
| --- | ---: | ---: |
| Reduced GNU, uncompressed | 3.227 | 343.7 |
| Reduced musl, uncompressed | 3.392 | 324.5 |
| Reduced GNU, UPX level 9 | 5.009 | 459.3 |
| Reduced musl, UPX level 9 | 4.546 | 479.9 |

These are medians of three fresh-site runs per candidate using the Phase 7 installer workload.
Build and browser-test jobs ran concurrently, and filesystem caches were not cleared.
RSS covers the serving process after ten installer requests, excluding any earlier startup-process peak.
The measurements do not establish a production-throughput difference between GNU and musl.

### Existing-site verification

Both raw and compressed candidates passed the installed-site network checks with unchanged caches.
Each check copied the entire baseline fixture and preserved its original absolute data-directory path.
The original fixture and the user's data directory remained untouched.

Checks covered:

- Remote administrator login.
- Persisted content access.
- Private-path protection.
- Default listener isolation.

Compressed artifact SHA-256 values:

```text
GNU  f361dc8f0ddb499aadea8472e0aa1de1a8cecca25aaa56b664a7c16cc39c6565
musl 1d446cec7033558822743a575c199fa9c347a2e1c606f71b57fea40628d81e38
```

### Offline browser verification

All four candidates passed the complete offline suite, including required-extension and PDO-driver assertions.

| Candidate | Successful suite duration, seconds |
| --- | ---: |
| Reduced GNU, uncompressed | 288.450 |
| Reduced musl, uncompressed | 391.941 |
| Reduced GNU, UPX level 9 | 290.234 |
| Reduced musl, UPX level 9, retry | 253.618 |

The first compressed musl run timed out during browser navigation after restart, then timed out while capturing the page source.
An independent login-page request returned HTTP 200 in 0.224 seconds while that run was stalled.
A single fresh retry passed with unchanged assertions and no skipped workflows.
The initial failure evidence remains in `/tmp/portable-drupal-minimal-musl-compressed-browser`.
The successful retry evidence is in `/tmp/portable-drupal-minimal-musl-compressed-browser-retry`.
Suite timings include concurrent host workloads and do not establish relative runtime performance.

## Handling compatibility findings

Resolve upstream version and extension compatibility in Phase 1 before building later workflows.
Investigate required downloads or writable application paths as failures of the offline packaging contract.
Phase 3 must verify that Drupal discovers bundled translations through its normal interfaces.
Phase 4 must verify content translation with the selected Byte configuration.

If compatibility requires changing the agreed scope, record the finding and obtain a decision before changing the requirement.

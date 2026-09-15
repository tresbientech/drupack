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
No implementation or compatibility tests have been completed yet.

## Phase 1: Install Byte offline in English

User stories: 1–4, 6–11, 22–23.

### What to build

Build the executable and complete a fresh Byte installation through Drupal's browser interface with network access disabled.
The user supplies site details and administrator credentials, then reaches the installed site backed by SQLite.
Use the default persistent data directory and loopback access.

Resolve compatible pinned dependencies, required extensions, embedded-file extraction behavior, and writable installation paths through this working path.
Define and document the launch command, default port, supported Linux distribution, and minimum `glibc` version.

### Acceptance criteria

- [ ] Docker produces the GNU Linux x86-64 executable from pinned dependencies.
- [ ] The executable starts on the documented baseline without separately installed application runtime tools.
- [ ] A fresh launch presents Drupal CMS's installer with Byte as the sole template.
- [ ] Installation completes in English with outbound network access disabled.
- [ ] The supplied credentials permit administrator login after installation.
- [ ] Byte's required assets load from the local package.
- [ ] The SQLite database and persistent installation settings use the default data directory.
- [ ] Default access is limited to loopback, and direct HTTP requests cannot retrieve database or private files.

## Phase 2: Preserve and isolate site data

User stories: 12–15, 20, 25.

### What to build

Create content and upload files through the installed site, then restart the executable and continue work.
Repeat the installation with a custom data directory.
Keep persistent writes within the selected site storage and verify HTTP access controls for private data.

### Acceptance criteria

- [ ] Content and uploads created offline remain available after process restart.
- [ ] Subsequent launches reuse the installed site and its administrator credentials.
- [ ] The data-directory option supports a fresh installation and subsequent reuse.
- [ ] Separate selected data directories retain independent site content and settings.
- [ ] Tests confirm persistent writable paths use the selected site storage.
- [ ] HTTP tests cover database files and private site files without exposing their contents.
- [ ] Replacing the executable with the same build preserves the installed site.

## Phase 3: Use interface languages offline

User stories: 16–17, 19.

### What to build

Bundle available interface translation resources for French, Simplified Chinese, Spanish, Hindi, and Arabic alongside the English interface.
Exercise Drupal's normal installation language selection and later language management without network access.
Verify Arabic administration with right-to-left layout.

### Acceptance criteria

- [ ] All six agreed languages are available through Drupal's standard language workflow while offline.
- [ ] Simplified Chinese uses the agreed `zh-hans` locale.
- [ ] A fresh installation can use an available non-English language through Drupal's normal installer.
- [ ] Users can add another bundled language after installation without a required download.
- [ ] Only languages selected through Drupal are installed; bundling does not activate all six.
- [ ] Language settings persist across restart.
- [ ] Arabic administration and core editing interactions remain usable with right-to-left layout.
- [ ] Upstream translation gaps are recorded without claiming complete translation of every string.

## Phase 4: Translate content offline

User story: 18.

### What to build

Use Drupal's existing content translation workflow with Byte and the bundled languages.
An editor enables translation where needed, creates content, adds a translation, and retrieves both language versions while offline.

### Acceptance criteria

- [ ] Drupal's standard interfaces expose content translation for a supported Byte content type.
- [ ] An editor can create original content and a translation with network access disabled.
- [ ] Both language versions can be retrieved through Drupal's normal site navigation or language routes.
- [ ] Translated content and its settings survive process restart.
- [ ] The workflow uses Drupal's existing permissions and interfaces.

## Phase 5: Enable network access explicitly

User stories: 4–5, 15.

### What to build

Provide a documented configuration path for access from another device.
Start an installed site with that configuration and exercise its browser interface remotely.
Retain loopback access as the default and preserve private-data protections in both configurations.

### Acceptance criteria

- [ ] An unmodified launch remains inaccessible through non-loopback interfaces.
- [ ] Explicit configuration permits a second device or isolated test client to access the site.
- [ ] The remotely accessed site uses the same persisted installation and Drupal authentication.
- [ ] Database and private-file HTTP protections pass under the network-access configuration.
- [ ] The documented configuration covers the intended listener and Drupal host handling.

## Phase 6: Verify fixed-bundle customization

User stories: 21–24.

### What to build

Enable eligible bundled modules and themes through Drupal and verify that the changes persist.
Then add a compatible component to the build inputs, rebuild the executable, and verify it through a fresh offline installation.
This demonstrates the supported path for application code additions without introducing runtime downloads or upgrades.

### Acceptance criteria

- [ ] An eligible bundled module can be enabled and its behavior exercised offline.
- [ ] An eligible bundled theme can be activated and rendered offline.
- [ ] Activation settings survive restart.
- [ ] A rebuilt executable includes an additional pinned component and supports a fresh offline installation.
- [ ] The new component can be exercised without runtime package downloads.
- [ ] The final artifact passes the earlier installation, persistence, language, content translation, and access-control checks.
- [ ] Release instructions describe the supported platform, startup, data storage, and rebuild workflow.

## Handling compatibility findings

Resolve upstream version and extension compatibility in Phase 1 before building later workflows.
Investigate required downloads or writable application paths as failures of the offline packaging contract.
Phase 3 must verify that Drupal discovers bundled translations through its normal interfaces.
Phase 4 must verify content translation with the selected Byte configuration.

If compatibility requires changing the agreed scope, record the finding and obtain a decision before changing the requirement.

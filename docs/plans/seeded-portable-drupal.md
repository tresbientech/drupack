# Plan: Seeded Portable Drupal

> Source: approved product decisions and the [project glossary](../../CONTEXT.md)

## Architectural decisions

- The package is a Linux x86-64 FrankenPHP executable with a fixed Drupal CMS application.
- Byte is the packaged site template.
- A Seed site is an installed Byte site with Local MCP Tools enabled.
- MCP Server source is included and disabled.
- SQLite is the default database backend.
- MySQL and PostgreSQL are selectable only on first start.
- SQL Server is out of scope.
- First start accepts administrator and remote database credentials through options or `PORTABLE_DRUPAL_*` variables.
- A selected data directory keeps site data outside the executable.
- `dr` exposes bundled Drush commands.
- Runtime Composer operations are out of scope.

## Phase 1: Finish package inputs

**User stories**: A site owner uses Byte with local agent tools from one executable.

### What to build

Complete the locked application inputs and static PHP runtime. Include Byte, Drush, MCP Tools, MCP Server source, and PostgreSQL support. Refresh embedded translations for the resolved dependency set.

### Acceptance criteria

- [ ] The dependency lock installs Byte, Drush, MCP Tools, MCP Server, and their dependencies.
- [ ] The static PHP runtime provides SQLite, MySQL, and PostgreSQL PDO drivers.
- [ ] The build downloads translations for the projects in the dependency lock.
- [ ] MCP Server remains disabled after package installation.
- [ ] The recorded MCP SDK advisory remains visible in testing documentation.

## Phase 2: Build and copy the SQLite Seed site

**User stories**: A site owner starts a working local website without Drupal's web installer.

### What to build

Build an installed SQLite Seed site during image creation. Apply Byte and enable MCP Tools. On a first SQLite launch, copy its database and files to Site data, then set the supplied administrator credentials.

### Acceptance criteria

- [ ] The image build produces an installed SQLite Seed site without network access at runtime.
- [ ] A new SQLite data directory receives the Seed site's database and writable files.
- [ ] First start requires administrator credentials through options or environment variables.
- [ ] The site starts without Drupal's web installer.
- [ ] MCP Tools is enabled and MCP Server is disabled.

## Phase 3: Provision MySQL and PostgreSQL

**User stories**: A site owner can create the same site in a selected server database.

### What to build

Provision Byte noninteractively when a new data directory selects MySQL or PostgreSQL. Read connection and administrator values from options or environment variables. Persist the selected database configuration in Site data.

### Acceptance criteria

- [ ] `--database` accepts `sqlite`, `mysql`, and `pgsql`.
- [ ] Missing remote connection values stop before persistent data is written.
- [ ] MySQL and PostgreSQL receive Byte and enabled MCP Tools.
- [ ] Later database migrations remain the site owner's responsibility.
- [ ] Database passwords never appear in normal command output.

## Phase 4: Expose Drush operations

**User stories**: A local agent can administer the site through the packaged command line.

### What to build

Expose the bundled Drush command set through `dr`. Select Site data with the existing option and `PORTABLE_DRUPAL_DATA_DIR`. Run commands against the persistent site that the executable starts.

### Acceptance criteria

- [ ] `dr` runs bundled Drush commands against selected Site data.
- [ ] `dr` works for SQLite, MySQL, and PostgreSQL sites after initialization.
- [ ] Local MCP Tools can use the packaged Drush runtime.
- [ ] MCP Server has no enabled transport or endpoint by default.
- [ ] Runtime Composer commands remain absent.

## Phase 5: Verify the static package

**User stories**: A site owner can trust first start, persistence, and local administration.

### What to build

Test observable behavior through built executables. Use temporary data directories and disposable remote database services for database-specific checks. Keep existing security and offline checks.

### Acceptance criteria

- [ ] SQLite first start reaches an installed Byte site without the web installer.
- [ ] MySQL and PostgreSQL first start complete noninteractive installation.
- [ ] Invalid backends and missing credentials produce stable failures without persistent writes.
- [ ] Content, uploads, and database settings survive restart.
- [ ] MCP Tools is enabled and MCP Server remains disabled.
- [ ] The package retains HTTP protection for database and private files.

## Phase 6: Update operator documentation

**User stories**: An operator can start and administer the package without hidden requirements.

### What to build

Replace web-installer documentation with the Seed site workflow. Document supported database choices, credential inputs, Site data, `dr`, and local agent access. State the MCP SDK advisory and testing status.

### Acceptance criteria

- [ ] Documentation names Byte as the packaged template.
- [ ] Documentation gives SQLite, MySQL, and PostgreSQL startup examples.
- [ ] Documentation lists required administrator and remote database inputs.
- [ ] Documentation describes `dr` and Local MCP Tools.
- [ ] Documentation states MCP Server is packaged and disabled.
- [ ] Documentation records the testing-only MCP SDK advisory.

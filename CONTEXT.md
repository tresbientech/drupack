# Drupack

Drupack distributes Drupal CMS for installation and use with persistent site data.

## Language

### Packaged site

**Drupack**:
The name of this project and of its Engine executable.
_Avoid_: Portable Drupal, portable-drupal

**Mercury Demo**:
The Packaged site each Drupack release publishes, built from the Mercury Demo Site template.

**Packaged site**:
A distributable Drupal application. One built from a Site template carries a Seed site. One built without serves a database that already holds its site.

**Site template**:
The Drupal CMS recipe that gives a new site its starting configuration and content. A Packaged site carries at most one, named in its Site contract. The Drupack release carries Mercury Demo.

**Site contract**:
The `drupack.yml` beside a site's `composer.json`, naming its executable, port, Site template, default site name, translations, settings file, build targets, added PHP extensions and Writable directories. A build writes it out as `site.json`, with the docroot `composer.json` names, and every other reader takes that file.

**Seed site**:
A preconfigured Drupal site state included in a Packaged site, installed from the Site template.

### Engine executable

**Engine executable**:
The `drupack` executable: the Runtime and Drupack's own files, with no application. It serves a Project folder.

**Project folder**:
A Drupal Composer project on disk, with its dependencies installed. An Engine executable serves it unchanged, under its own settings.
_Avoid_: served folder, codebase

### Site data

**Site data**:
The persistent information of one installed site, including its content and, unless a start names a Files directory, its uploaded files.

**Files directory**:
The directory holding a site's public files, addressed as `sites/default/files`. It is `files` in Site data unless `--files-dir` names another, which the Listener record keeps.

**First start**:
The start that turns an empty Site data directory into an installed site.

**`dr` command**:
The public command-line interface that exposes the Drush command set for a Packaged site and its Site data. Global options precede the command. It uses `./data` unless the user selects another Site data directory. For an Engine executable, it runs the Project folder's own Drush.

**`clean` command**:
The public command that removes unpacked releases from the cache.

**`DRUPACK_DATA_DIR`**:
An environment variable that selects the default Site data directory for a Packaged site.

**Version record**:
The Drupack and Drupal versions that last served a Site data directory.

**Listener record**:
The listen address and permitted host the last start served on, and the Files directory it named, held in Site data.

**Database backend**:
SQLite, MySQL, or PostgreSQL, selected when a Packaged site first starts. It stores the structured part of Site data.

**Serving lease**:
The exclusive claim one start holds over a Site data directory, from the moment it begins preparing until its server exits. A second start refuses or hands over, whatever address it was given.

### Paths

**Canonical path**:
The written form Drupack uses for every path it computes, exports or prints: absolute, forward slashes, no trailing separator, no `.` or `..` segment.

**Minted segment**:
A path element Drupack names itself rather than inherits, matching `^[a-z][a-z0-9.-]{0,31}$`, which the build refuses to break.

### Runtime

**Runtime**:
The PHP interpreter and web server a Packaged site runs on, built against one C library.
_Avoid_: naming the Application root or the `runtime` directory in Site data a Runtime.

**Application root**:
The directory holding a site's unpacked application: one shared read-only copy per release, or the site's own copy in Site data when its Site contract names a Writable directory.

**Writable directory**:
A directory of the application, named in the Site contract, that the site writes into at runtime.
_Avoid_: owned directory, site directory

### Extensions

**Extension allowlist**:
The record of every PHP extension a Packaged site ships, each with what it serves.

**Declared extension**:
A PHP extension that a package in the application's lock file names as a requirement.

## Relationships

### First start

- A **Packaged site** includes at most one **Site template**, chosen when it is built.
- A **Packaged site** without a **Site template** refuses a SQLite first start and adopts a database that holds its site.
- A SQLite first start creates **Site data** from the **Seed site**.
- A MySQL or PostgreSQL first start installs the **Site template** into new **Site data**.
- First start names the site after its **Site contract** unless the user chooses another name.
- A **Seed site** has one **Database backend**.
- SQLite is the default **Database backend** for the **Seed site**.
- First start selects the **Database backend** for a **Packaged site**.
- First start uninstalls `automatic_updates` and `package_manager`, which a read-only application cannot run.

### Site data

- **Site data** keeps the name first start gave the site.
- **Site data** survives replacement of the **Packaged site** with an updated release.
- **Site data** holds a **Version record**, which a **Packaged site** rewrites on every start.
- A **Packaged site** older than the **Version record** refuses to serve.
- The **`dr` command** manages one **Packaged site** and its selected **Site data**.
- **`DRUPACK_DATA_DIR`** selects **Site data** when no `--data-dir` option is present.

### Runtime

- A Linux **Packaged site** carries one **Runtime** per C library and picks one per host.
- A macOS or Windows **Packaged site** carries one **Runtime**.
- `--version` names the **Runtime** that ran.
- A **Runtime** and an **Application root** each unpack to their own cache. The **`clean` command** removes entries from both.
- A **Packaged site** whose **Site contract** names a **Writable directory** keeps its **Application root** in **Site data**, which the **`clean` command** never touches.
- An upgrade of such a **Packaged site** lays the new release into **Site data** and carries over each entry of a **Writable directory** that the release does not ship.

### Engine executable

- An **Engine executable** has no **Site data**. A **Project folder**'s own settings name its database and files.
- An **Engine executable** refuses a **Project folder** with a **Declared extension** its **Runtime** lacks.
- A Linux **Engine executable** carries one **Runtime** per C library, as a Linux **Packaged site** does.

### Extensions

- The **Extension allowlist** gives every **Declared extension** a verdict, kept or dropped, and a build stops on one it does not name.
- The **Extension allowlist** also carries extensions no package declares, among them the drivers for every **Database backend** a first start can select.
- Each platform build takes the extensions it compiles from the **Extension allowlist**.

## Example dialogue

> Developer: "Does the packaged site include an installed website?"
> User: "It copies the Seed site on a SQLite first start. MySQL and PostgreSQL install the Site template instead. Its site data then persists across launches."
> Developer: "Can I name the site myself?"
> User: "On a first start, with --site-name. After that the name belongs to the site, and Drupack never rewrites it."

## Flagged ambiguities

- `runtime` named four things: the PHP application files, the compiled interpreter and server, the `runtime` directory in Site data, and a Go package. Resolved: a **Runtime** is the compiled interpreter and server. [ADR 0017](docs/adr/0017-one-directory-per-artifact.md) gives the repository layout that follows.

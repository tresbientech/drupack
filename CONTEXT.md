# Drupack

Drupack distributes Drupal CMS for installation and use with persistent site data.

## Language

**Drupack**:
The name of this project and of its Packaged site.
_Avoid_: Portable Drupal, portable-drupal

**Packaged site**:
A distributable Drupal CMS application with a Seed site.

**Site template**:
The predefined starting configuration in a Seed site.

**Seed site**:
A preconfigured Drupal site state included in a Packaged site.

**Site data**:
The persistent information belonging to a Seed site, including its content and uploaded files.

**`dr` command**:
The public command-line interface that exposes the Drush command set for a Packaged site and its Site data. Global options precede the command. It uses `./data` unless the user selects another Site data directory.

**`DRUPACK_DATA_DIR`**:
An environment variable that selects the default Site data directory for a Packaged site.

**Database backend**:
SQLite, MySQL, or PostgreSQL, selected when a Packaged site first starts. It stores a Seed site's structured Site data.

**Local MCP Tools**:
The Drupal MCP Tools feature that lets local AI agents use a Packaged site.

**MCP Server**:
The Drupal MCP Server feature included in a Packaged site for later enablement by a site administrator.


## Relationships

- A **Packaged site** includes exactly one **Site template** for installation: Drupal CMS Blank.
- First use of a **Packaged site** creates **Site data** from its **Seed site**.
- **Site data** survives replacement of the **Packaged site** with an updated release.
- The **`dr` command** manages one **Packaged site** and its selected **Site data**.
- **`DRUPACK_DATA_DIR`** selects **Site data** when no `--data-dir` option is present.
- A **Seed site** has one **Database backend**.
- SQLite is the default **Database backend** for the **Seed site**.
- First start selects the **Database backend** for a **Packaged site**.
- **Local MCP Tools** are enabled in the **Seed site**.
- **MCP Server** is disabled in the **Seed site**.

## Example dialogue

> Developer: "Does the packaged site include an installed website?"
> User: "It copies the Seed site on first use. Its site data then persists across launches."

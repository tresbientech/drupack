# Drupack

Drupack distributes Drupal CMS for installation and use with persistent site data.

## Language

### Packaged site

**Drupack**:
The name of this project and of its Packaged site.
_Avoid_: Portable Drupal, portable-drupal

**Packaged site**:
A distributable Drupal CMS application with its Site template and a Seed site.

**Site template**:
The Drupal CMS recipe that gives a new site its starting configuration and content. A Packaged site carries one, Mercury Demo.

**Seed site**:
A preconfigured Drupal site state included in a Packaged site, installed from the Site template.

**Local MCP Tools**:
The Drupal MCP Tools feature that lets local AI agents use a Packaged site.

**MCP Server**:
The Drupal MCP Server feature included in a Packaged site for later enablement by a site administrator.

### Site data

**Site data**:
The persistent information of one installed site, including its content and uploaded files.

**`dr` command**:
The public command-line interface that exposes the Drush command set for a Packaged site and its Site data. Global options precede the command. It uses `./data` unless the user selects another Site data directory.

**`DRUPACK_DATA_DIR`**:
An environment variable that selects the default Site data directory for a Packaged site.

**Version record**:
The Drupack and Drupal versions that last served a Site data directory.

**Database backend**:
SQLite, MySQL, or PostgreSQL, selected when a Packaged site first starts. It stores the structured part of Site data.

## Relationships

### First start

- A **Packaged site** includes one **Site template**, chosen when it is built.
- A SQLite first start creates **Site data** from the **Seed site**.
- A MySQL or PostgreSQL first start installs the **Site template** into new **Site data**.
- First start names the site, `Drupal Mercury Demo` unless the user chooses another name.
- A **Seed site** has one **Database backend**.
- SQLite is the default **Database backend** for the **Seed site**.
- First start selects the **Database backend** for a **Packaged site**.
- First start enables **Local MCP Tools**.
- First start leaves **MCP Server** disabled.

### Site data

- **Site data** keeps the name first start gave the site.
- **Site data** survives replacement of the **Packaged site** with an updated release.
- **Site data** holds a **Version record**, which a **Packaged site** rewrites on every start.
- A **Packaged site** older than the **Version record** refuses to serve.
- The **`dr` command** manages one **Packaged site** and its selected **Site data**.
- **`DRUPACK_DATA_DIR`** selects **Site data** when no `--data-dir` option is present.

## Example dialogue

> Developer: "Does the packaged site include an installed website?"
> User: "It copies the Seed site on a SQLite first start. MySQL and PostgreSQL install the Site template instead. Its site data then persists across launches."
> Developer: "Can I name the site myself?"
> User: "On a first start, with --site-name. After that the name belongs to the site, and Drupack never rewrites it."

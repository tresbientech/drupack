# Drupack

Drupack distributes Drupal CMS for installation and use with persistent site data.

## Language

### Packaged site

**Drupack**:
The name of this project and of its Packaged site.
_Avoid_: Portable Drupal, portable-drupal

**Packaged site**:
A distributable Drupal CMS application with its Site templates and a Seed site.

**Site template**:
A Drupal CMS recipe that gives a new site its starting configuration and content.

**Retired Site template**:
A Site template that first start no longer offers. Its modules and theme stay in the Packaged site.

**Seed site**:
A preconfigured Drupal site state included in a Packaged site, installed from the default Site template.

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

- A **Packaged site** includes a fixed set of **Site templates**, chosen when it is built.
- First start installs Mercury Demo unless the user selects another **Site template**.
- A SQLite first start of the default **Site template** creates **Site data** from the **Seed site**.
- Any other first start installs the selected **Site template** into new **Site data**.
- A **Seed site** has one **Database backend**.
- SQLite is the default **Database backend** for the **Seed site**.
- First start selects the **Database backend** for a **Packaged site**.
- First start enables **Local MCP Tools**, whatever the **Site template**.
- First start leaves **MCP Server** disabled.

### Site data

- **Site data** records the **Site template** it was installed from.
- A site installed from a **Retired Site template** keeps running on later releases.
- **Site data** survives replacement of the **Packaged site** with an updated release.
- **Site data** holds a **Version record**, which a **Packaged site** rewrites on every start.
- A **Packaged site** older than the **Version record** refuses to serve.
- The **`dr` command** manages one **Packaged site** and its selected **Site data**.
- **`DRUPACK_DATA_DIR`** selects **Site data** when no `--data-dir` option is present.

## Example dialogue

> Developer: "Does the packaged site include an installed website?"
> User: "It copies the Seed site when a SQLite first start picks Mercury Demo. Another Site template installs from its recipe. Its site data then persists across launches."
> Developer: "Can I switch to another Site template later?"
> User: "No. Site data records the Site template it was installed from, and a later start only warns when you name another one."

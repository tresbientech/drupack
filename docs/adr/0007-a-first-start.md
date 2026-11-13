# A first start runs typed steps, reads a typed Site contract and signs the reader in with a one-time link

## Context

A first start turns an empty Site data directory into an installed site. It must work with no terminal, in a container, a CI job or a systemd unit. It must stop cleanly at a step it does not know, and PHP must read the same `site.json` the Go build writes.

## Decision

### Steps

- `Step` is a string-backed enum in `application/support`. Its values are the names the progress file records.
- A SQLite first start owes `seed`, `settings` and `administrator`. A MySQL or PostgreSQL one owes `settings`, `install` and `modules`. A Site data that already holds `settings.php` owes `adopt`.
- `Step::needs()` names what a step needs: `Need::Recipe`, `Need::Connection` or `Need::Credentials`. `Step::owed()` merges the needs of a list.
- `requireNeeds()` in `application/operate.php` holds the refusals, because they name command-line options. The administrator's credentials are generated instead of requested.
- `SiteData::steps()` refuses a progress file that names an unknown step, before any step runs.

### The recorded connection

- `settings.php` in Site data records the connection. A later start takes each `--db-*` option it names in place of the recorded value.
- `prepare` connects to a MySQL or PostgreSQL server before it rewrites `settings.php`. A refused connection fails the start before the ready line and records nothing.
- A later start refuses a `--database` other than the recorded backend.
- A SQLite database is a file in Site data, and no start opens it before it serves.

### The Site contract

- `SiteContract` in `application/support` holds the `site.json` fields PHP reads, with their types: the recipe, the settings file and the site name. A missing or mistyped key fails at read time and names the constructor parameter.
- `application/tests/site.json` is a reference file. A Go test compares it with what `siteconfig.Write` produces for a fixed site, and the PHP tests build their contract from it.

### The Site template

- A SQLite first start copies the Seed site that the build installed from the Site template.
- A MySQL or PostgreSQL first start runs `drush site:install` on the template's recipe.
- A site built without a recipe refuses a SQLite first start and adopts a database that already holds its site.
- Both paths uninstall `automatic_updates` and `package_manager`, which a read-only application cannot run. A resumed step skips modules already removed.
- A site takes the name its contract holds unless `--site-name` or `DRUPACK_SITE_NAME` says otherwise. A later start never renames it.

### The login link

- A first start creates `admin`, or the `--admin-user` name, with a password from `random_bytes` that nobody sees. `--admin-password` sets it explicitly.
- A start a person watches asks Drush for a one-time login link for uid 1, and prints it. A site's link lands on `/admin/dashboard`. A project folder's link comes from the folder's own Drush, with no destination.
- The server opens a browser once the site answers: on the login link, else on `/core/install.php` for a project folder with no installed site, else on the site URL.
- A person watches when the start has a terminal, a file manager's console, or a detaching parent that had a terminal.
- Any other start mints no link. It prints the command that mints one: `NAME drush --data-dir DIR user:login /admin/dashboard` for a site, `NAME drush user:login --uri=URL` for a project folder.
- `--no-browser`, a bind address outside loopback, or a start nobody watches opens no browser.
- A failed mint prints Drush's reason and the command, then serves. It fails the start when the start generated the password and adopted no site.
- An adopted site keeps its own accounts and needs no link.

## Reason

A prompt for a name and password, twice and hidden, fails without a terminal. A generated password nobody sees keeps the account from being passwordless. The link signs the reader in on the dashboard, where they set their own password.

The link is a working credential for uid 1. A start under systemd or in a container writes its output to a log. It prints the command instead, and an operator mints the link on the host.

A database server's address, port and password change under a running site, and a start that kept the recorded values served 500 to every request after its ready line. Taking the named value once the server accepts it lets the start that a reader corrects fix the record.

With bare step names, each function derives its own precondition, and a progress file from another release fails after earlier steps have run. Typed steps put each rule in one case. A reference `site.json` ties the PHP reader to the Go writer, so a change to the file fails a test on both sides.

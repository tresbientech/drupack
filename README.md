# Portable Drupal CMS

Package Drupal CMS with Byte, SQLite, and FrankenPHP in one Linux executable.
Site data lives in a separate directory.
Offline installation and the release acceptance checks pass with the pinned dependencies.

## Build

The build requires Docker with BuildKit and internet access.
Run from this repository on Linux x86-64:

```sh
docker build --target artifact --output type=local,dest=dist .
```

The artifact contains the application dependencies and PHP runtime.
Its supported host baseline is Debian 12 x86-64 with glibc 2.36 and a browser.
The host does not need a separate PHP installation or database server.

The dependency lock pins Byte 1.0.3 and Canvas 1.10.1.
Canvas 1.11 has an upstream [site-template installation regression](https://git.drupalcode.org/project/canvas/-/work_items/3592052).

## Start a site

```sh
./dist/portable-drupal php-cli launch.php
```

Open `http://localhost:8080` and complete Drupal's installer.
Byte is the sole site template.
The default data directory is `./data`, relative to the directory where you run the command.
Subsequent launches reuse that site's database and settings.

Select another data directory with:

```sh
./dist/portable-drupal php-cli launch.php --data-dir /path/to/site-data
```

Stop the process with Ctrl+C.

## Access from another device

The default listener accepts local connections only.
Enable network access explicitly:

```sh
./dist/portable-drupal php-cli launch.php --listen 0.0.0.0:8080 --host drupal.example.test
```

Point that hostname at the host computer, then open `http://drupal.example.test:8080` on the other device.
The `--host` value also configures Drupal's trusted host check.
This listener uses HTTP.
Localhost remains an accepted host name.

## Languages

Drupal controls language selection and content translation through its standard interfaces.
Bundled translation files remain inactive until the user selects a language.

The package includes English and available translation resources for:

- French (`fr`)
- Simplified Chinese (`zh-hans`)
- Spanish (`es`)
- Hindi (`hi`)
- Arabic (`ar`)

English is built into Drupal.
Upstream translation gaps are recorded in [the translation manifest](packaging/translations.json).

## Keep site data

The selected data directory contains:

- `site.sqlite`: the site's database.
- `settings.php` and `hash_salt`: persistent configuration and the site secret.
- `files/`: public uploads and translation files.
- `private/`: files outside the public web root.
- `config/`: configuration exports.
- `tmp/` and `runtime/`: temporary files and the extracted application.

Stop the process before copying the entire data directory for backup.
Keep backups private because they contain the database and site secret.
Restore backups at the original absolute data-directory path.
Relocation requires additional handling of Drupal's cached paths and extracted application links.
Replacing the executable with the same build preserves the selected site's data.
Compatibility across application versions is outside this release's scope.

## Change bundled code

Modules and themes included in the artifact can be enabled through Drupal.
Additional code requires an updated Composer dependency lock and a rebuilt executable.
Validate the rebuilt artifact with a fresh installation.
This release excludes:

- Runtime package installation.
- Upgrade commands.
- Email configuration.

## Run acceptance checks

The tests require Docker and the test images referenced by their scripts.
The browser test disables container networking.
The network test uses an internal Docker network with a separate client.

```sh
bash tests/offline.sh ./dist/portable-drupal
bash tests/network.sh ./dist/portable-drupal
```

Each script prints its results directory.
Use a fresh results directory for each network test run.
The network script accepts an installed data directory as its third argument to test remote login against a copied site.

Verified on 15 September 2026:

- Offline English and French installation, including a custom data directory.
- Content and uploaded images retained after restart and replacement with the same executable build.
- All agreed language imports and content translation through Drupal's standard interfaces.
- Arabic content editing through Drupal's right-to-left interface.
- Explicit remote access with authentication and private-file protection.
- Bundled component activation and a separately rebuilt artifact with Devel enabled.

The current executable is approximately 450 MiB. Size reduction is scheduled as phase 7.

The [PRD](docs/prd/portable-drupal.md) defines the release scope.
The [implementation plan](docs/plans/portable-drupal.md) lists phase acceptance criteria.

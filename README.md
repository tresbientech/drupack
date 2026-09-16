# Portable Drupal CMS

This project builds one Linux x86-64 FrankenPHP executable. It contains Drupal CMS Blank, PHP, SQLite, MySQL and PostgreSQL drivers, Drush, Local MCP Tools, and MCP Server source.

## Build

Build on Linux x86-64 with Docker and BuildKit.

```sh
docker build --target artifact --output type=local,dest=dist .
```

The output is `dist/portable-drupal`. The host needs no PHP, Composer, or database server for SQLite use.

## Start a SQLite site

First start copies an installed Drupal CMS Blank Seed site into `./data`. It requires administrator credentials.

```sh
./dist/portable-drupal \
  --admin-user admin \
  --admin-password 'choose-a-password'
```

Open `http://localhost:8080`. Drupal's web installer does not run. Later starts use the existing Site data and need no credentials.

`--data-dir` selects another Site data directory. `PORTABLE_DRUPAL_DATA_DIR` supplies its default.

## Start a server database site

MySQL and PostgreSQL install the same Blank site on first start.

```sh
./dist/portable-drupal --database mysql \
  --db-host 127.0.0.1 --db-name drupal \
  --db-user drupal --db-password 'database-password' \
  --admin-user admin --admin-password 'choose-a-password'
```

Use `--database pgsql` for PostgreSQL. `--db-port` is optional. MySQL defaults to `3306`; PostgreSQL defaults to `5432`.

The equivalent environment variables are `PORTABLE_DRUPAL_DATABASE`, `PORTABLE_DRUPAL_DB_HOST`, `PORTABLE_DRUPAL_DB_PORT`, `PORTABLE_DRUPAL_DB_NAME`, `PORTABLE_DRUPAL_DB_USER`, `PORTABLE_DRUPAL_DB_PASSWORD`, `PORTABLE_DRUPAL_ADMIN_USER`, and `PORTABLE_DRUPAL_ADMIN_PASSWORD`.

The executable stores database configuration in Site data. Database migrations and later `settings.php` changes belong to the site owner.

## Administer with Drush

`dr` runs the bundled Drush command set against Site data. Put portable options before the Drush command.

```sh
./dist/portable-drupal dr --data-dir ./data status
./dist/portable-drupal dr --data-dir ./data pm:list --status=enabled
```

Runtime Composer operations are not included.

## Local MCP tools

The Seed site enables `mcp_tools`. It supports local agents that administer the Drupal site. The package includes `mcp_server` source but does not enable it or expose a transport.

The current dependency lock includes `mcp/sdk` 0.6.0. Its upstream advisory GHSA-7m52-jw36-44r3 is high severity. This package is for testing and is not a release artifact.

## Site data

Site data contains the database, uploads, private files, generated settings, hash salt, configuration exports, and runtime files. Stop the executable before copying Site data for backup. Keep backups private.

## Tests

Build an uncompressed test binary when UPX compression is unnecessary.

```sh
docker build --target uncompressed --output type=local,dest=dist/uncompressed .
bash tests/database-init.sh ./dist/uncompressed/portable-drupal
bash tests/offline.sh ./dist/uncompressed/portable-drupal
bash tests/network.sh ./dist/uncompressed/portable-drupal
```

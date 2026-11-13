# A 1.x release keeps the engine's names and scripted behaviour, and packaged sites stay experimental

## Context

No document said what a stable release promises. A reader could not tell which commands, options and keys a 1.x release keeps, or which change waits for 2.0.0. The engine serves Composer projects in production, where a unit file and a deploy script name its commands. Packaged sites still change shape with each release.

## Decision

### What a 1.x release keeps

The promise covers `drupack`, the engine executable, from 1.0.0.

Names:

- the commands and their options
- the environment variables `DRUPACK_CACHE_DIR`, `DRUPACK_CA_FILE`, `DRUPACK_TLS_DIR`, `DRUPACK_DNS_ANSWER` and `DRUPACK_NO_UPDATE_CHECK`
- `DRUPACK_LIBC`, which the install script reads
- the three `DRUPACK_MERCURE_` variables a Mercure hub start exports
- the `extra.drupack` keys of `composer.json`: `mercure`, `subdomain` and `static`
- the `drupal/drupack` package and its bin script, `vendor/bin/drupack-install`
- the release file names, `drupack-VERSION-TARGET`, with `checksums.txt`, `release.json`, `install-drupack.sh` and `install-drupack.ps1`
- the install addresses, `https://drupack.tresbien.tech/install.sh` and `install.ps1`

Behaviour a script depends on:

- exit 0 for a success and another code for a failure
- the default listener, `127.0.0.1:3787`
- refusals on standard error
- the fields of `release.json`

### What any release may change

- the wording of a message
- the layout of the cache
- the format of a log line
- the versions of FrankenPHP, Caddy and Composer a release carries

### The rule per release

- A patch release changes nothing in the kept list, and keeps the PHP minor it carries.
- A minor release adds to the kept list. It may move PHP to the next minor, and its release notes say so.
- A removal, a rename and a changed default wait for 2.0.0.

### Experimental parts

- Packaged sites are experimental within 1.x: the build, `drupack.yml`, `build.yml`, a site's commands and options, and Site data.
- A minor release may break them, and its release notes name each break. A patch release never does.
- No release promises to open Site data an earlier release wrote.
- `docs/cli.md` puts the engine first and opens with the promise. `README.md` and `docs/build-your-site.md` mark packaged sites experimental.

## Reason

The engine has the smaller surface, and a production server needs a promise first. A packaged site adds a build contract and a data folder that later releases must read. That promise needs the database update on a new release, which is not built.

A unit file reads an exit code and a deploy script reads a default port, so both are part of the promise. A frozen message would make every clearer refusal wait for a major version.

PHP publishes a minor each year and ends its support after four. A 1.x tied to one PHP minor would force a major release a year. A project whose `composer.json` excludes the new PHP is refused by name at the start, and stays on its release until it updates.

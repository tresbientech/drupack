# Windows path handling breaks icons and component assets

Proposed on 2026-09-21. Implemented and merged into main.

## The question

On Windows, Drupack renders no icons, and every component asset URL carries the
reader's disk path. Both defects come from Drupal core. Drupack patches no
upstream code today. Where does the fix go?

## What happens today

Evidence comes from a running install: Drupack 0.1.8, Drupal core 11.4.7,
Windows 11, Site data at `C:\Users\theno\Downloads\data`.

### Icons render empty

The page `/card-components` holds 16 icon wrappers. All 16 are empty:

```html
<button class="navbar--hide-menu" aria-label="Hide menu">
  <div class="min-w:32">
  </div>
</button>
```

The `svg` extractor reads each icon from disk and inlines its content.
`IconFinder::getFileContents()` refuses the read:

```php
$url = parse_url($uri);
if (isset($url['scheme']) || isset($url['host'])) {
  return FALSE;
}
```

`parse_url()` reads a drive letter as a URL scheme:

```
parse_url("C:/Users/theno/.../icons/phosphor\acorn.svg")
=> ['scheme' => 'C', 'path' => '/Users/theno/.../icons/phosphor\acorn.svg']
```

`SvgExtractor::loadIcon()` returns NULL for every icon. The site's `icon_pack`
cache holds 1557 icons in six packs, among them `navigation`, `dashboard` and
`phosphor`.

Each cached `source` URL also carries a backslash, such as
`/themes/contrib/mercury/icons/phosphor\acorn.svg`. Caddy serves those. A
request with the raw path returned 200. The `source` value takes no part in
this failure, and normalising it fixes nothing.

### Component asset URLs carry the disk path

The same page holds 30 references of this shape:

```html
<img src="/C%3A/Users/theno/Downloads/data/runtime/frankenphp_0902972b.../web/themes/contrib/mercury/components/card/assets/jj-ying-8bghKxNU1j0-unsplash.jpg">
```

Core builds an extension directory with a forward slash, then appends further
elements with `DIRECTORY_SEPARATOR`. The cached definition carries both:

```
"path" => "C:/Users/.../web/themes/contrib/mercury\components\hero-billboard"
```

`ComponentMetadata::__construct()` then fails to strip the app root:

```php
$app_root = rtrim($app_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (str_starts_with($path, $app_root)) {
  $path = substr($path, strlen($app_root));
}
```

The prefix ends in `\` while the path continues with `/themes`. The comparison
fails, so the path stays absolute. Canvas
`SingleDirectoryComponent::rewriteExampleUrl()` then runs `Path::canonicalize()`,
which flips every backslash, confirms the file exists, and returns
`Url::fromUri('base:/' . $absolutePath)`.

Linux runs the same code without fault. There `DIRECTORY_SEPARATOR` is `/`, so
the prefix matches and the path comes out relative to the app root.

### Upstream state

- Core [#3579927](https://www.drupal.org/project/drupal/issues/3579927) reports
  the icon defect. Status: needs work. Its MR !15388 does not fix it. Run
  against a real Windows path, the patched body still returns FALSE. `'\\\\'`
  in single quotes is two backslashes, and `realpath(...) ?: $uri` never yields
  FALSE, so the guard behaves as before the patch.
- Canvas [#3584619](https://git.drupalcode.org/project/canvas/-/issues/3584619)
  reports the asset defect against Canvas. The wrong value comes from core.
- Core [#3367556](https://www.drupal.org/project/drupal/issues/3367556) fixed
  this separator class for CSS and JS in `makePathRelativeToLibraryRoot()`.
  Component paths were left behind.

## Decision

Drupack registers one container service provider from `settings.php` and
replaces two service classes with subclasses of its own.

- `runtime/support/` holds the classes, under namespace `Drupack\Support\`.
  The Dockerfile already copies `runtime/` into the application root, so they
  ship at `/app/support/`.
- `DriveLetterIconFinder` extends `IconFinder` and overrides
  `getFileContents()`. It asks the gate before it touches the filesystem, so a
  refused URI never reaches `file_exists()` and never resolves a stream wrapper.
- `RootRelativeComponentPluginManager` extends `ComponentPluginManager` and
  overrides `alterDefinition()`. It rewrites `path` to the form Linux already
  produces: forward slashes, relative to the application root.
- `WindowsPathServiceProvider` calls `setClass()` on both definitions, so core
  keeps its own constructor arguments.
- Both overrides run on every platform. The rewrite is an identity on Linux and
  macOS, so the conformance suite exercises the shipped code path there.

`settings.php` registers the provider:

```php
$GLOBALS['conf']['container_service_providers']['drupack'] =
  'Drupack\Support\WindowsPathServiceProvider';
```

`DrupalKernel::discoverServiceProviders()` reads that key and calls
`class_exists()` on the value, so the classes need PSR-4 autoloading and no
module.

## Why not patch core

Patch files fix the cause and read as the upstream diff. They also need a patch
step in the build and a rebase on every core update. Two subclasses need
neither. They also survive a core update that changes constructor arguments,
because `setClass()` leaves the argument list alone.

A Caddy rewrite reaches neither defect. The icon read never leaves PHP. The
asset URL embeds `frankenphp_<checksum>`, which changes with each release, so
cached markup would strand on every upgrade.

## Consequences

- The application gains one autoloaded namespace and one service provider.
  Nothing in the site UI shows them, and no site owner can uninstall them.
- `composer install` runs before `COPY runtime/ ./`, so the build gains a
  `composer dump-autoload --optimize` between that copy and the seed install.
  The seed install builds the container, so the provider loads there first.
- Two core classes now have a Drupack subclass. A core release that changes
  either method body needs a read of the override.
- The rendered page stops publishing the reader's folder layout.

## Tests

`tests/conformance/windows_paths_cases.py`, running on all three platforms:

- A started site serves a page whose icon elements carry SVG content.
- No rendered URL contains a drive letter or an encoded `%3A`.
- No rendered URL contains a backslash or an encoded `%5C`.
- A component example asset URL answers 200.

The cases assert on rendered markup, so they fail on Windows today and pass on
Linux today. That difference proves the case reaches the defect.

## Considered options

- Patch core in the Dockerfile. Fixes the cause, and produces a diff worth
  sending upstream. Rejected for the rebase cost on each core update.
- Rewrite paths in the Caddyfile. Rejected: it cannot reach the icon read, and
  it strands cached markup on every release.
- Ship a Drupal module. Rejected: it needs enabling at seed install, appears at
  `/admin/modules`, and a site owner can uninstall it.
- Redefine both services in a `container_yamls` file. Rejected: a YAML
  redefinition replaces the whole definition, so ten constructor arguments would
  need copying and keeping in sync.

## Out of scope

- Filing either fix upstream. The analysis and a working diff shape exist for
  both.
- Hardening Canvas `rewriteExampleUrl()`, which builds a URL from an absolute
  path with no check that the path is relative.

# One function builds a child's environment

Proposed on 2026-11-09. Lands after [the site address RFC](site-address-resolution.md), whose `Address` it reads.

## Problem

The launcher runs five kinds of child: the reader's own `php` or Node line, the server, `operate.php`, Drush and export. About 35 `put` and `drop` calls in `plan.go`, `sitestart.go`, `start.go` and `drush.go` build their environments.

- The `PATH` and `DRUPACK_DRUSH_SCRIPT` pair is written at `drush.go:24-32`, at `sitestart.go:417-418`, and at `start.go:253` with `sitestart.go:348`.
- `runtime/environment.txt` declares the 15 `DRUPACK_RUNTIME_*` names. `test_environment.py` checks them.
- `DRUPACK_CA_FILE`, `DRUPACK_MERCURE_*`, `DRUPACK_DRUSH_SCRIPT`, `DRUSH_OPTIONS_URI` and the `DRUPACK_DB_*`, `DRUPACK_ADMIN_*`, `DRUPACK_DATABASE` inputs are declared nowhere.
- `TestServerEnvironment` tests `serverEnvironment`. `execServer`, which adds the remaining variables, has no test.

Reading what one child receives takes four files.

## Proposed interface

New file `launcher/childenv.go`, package `main`.

```go
type Kind int

const (
	Reader Kind = iota // Plan()'s php, php-cli and Node lines
	Server
	Operate
	Drush
	Export
)

type Facts struct {
	Parent []string
	Fold   bool
	Dir, Name, Version, Components, NodeBin string
	Engine      bool
	Application string // the app the child runs
	Project     string // engine: the project whose vendor/ holds Drush
	Data, Files, Scratch, StaticRoot string // "" in engine mode
	Address     struct{ Host, URL string }
	CAFile      string
	Mercure     *Mercure // Publisher, Subscriber, URL
	Inputs      map[string]string // typed start options by name
	Alias, Open string
	Serve       struct{ ID, Probe, StopRecord, TLSRoot string }
}

func (f Facts) Env(k Kind) []string
```

A start fills `Facts` in order: paths, TLS choice, CA bundle, login link. Each launch calls `f.Env(kind)` on the value it holds at that moment.

### Callers

```go
operate(l, f.Env(Operate), &owed, false, "check", "site", data)

f.CAFile = bundle          // storeBundle returns the path
f.Mercure = hub            // putMercure returns the secrets
f.Open, f.Alias = link, choice.Alias
execServer(..., f.Env(Server))

Command{Env: f.Env(Drush)} // site and engine drush
```

### What `Env` hides

- name folding on Windows
- the base rules: loader variables dropped, `PHPRC`, the default CA file, name, version, components, `APP_DIR`
- which kind receives which variable, in site mode and in engine mode
- the `PATH` prefix and the Drush script path
- value forms: canonical paths, bracketed IPv6 hosts, the scratch variable names

## Dependency strategy

In-process. `Env` reads a value and returns a slice. File work stays in the callers: `storeBundle` writes the bundle and returns its path, and `putMercure` reads the secrets and returns them.

## Declared names

`runtime/environment.txt` stays the one declaration and covers every name the launcher sets.

- Each undeclared name gets a line.
- Each line gains `kinds=` and `modes=`.
- `launcher/childenv.go` becomes the only Go writer.

`TestEnvDeclared` reads the file, fills every field of `Facts`, and runs each kind in both modes. It asserts that the emitted `DRUPACK_*` and `DRUSH_*` names equal the declared ones. `test_environment.py` keeps its reader checks, with its name pattern widened beyond `RUNTIME_`.

## Testing strategy

New tests:

- `TestEnvKinds`: a table of `Facts` in and environment out, per kind and mode.
- `TestEnvDeclared`, described above.

Existing tests:

| Test | Outcome |
|---|---|
| `TestServerEnvironment` | Rewritten as `TestEnvServer`, same three rows: plain HTTP, mkcert, Drupack's own root. |
| `TestPlanEnvironment` | Rewritten as `TestEnvReader`, same cases. |
| `TestSiteDrushEnvironment`, `TestEngineDrushEnvironment`, `TestEngineDrushReadsAHostnameListener`, `TestEngineDrushWithoutAServedHub` | Preserved. They assert the `Command` end to end. |
| `environmentOf` assertions in `export_test.go` | Preserved. Their `environ{…}` arguments become `Facts{…}`. |
| `test_environment.py` | Rewritten for the wider name pattern and the two new columns. |

## Implementation recommendations

Deleted with this change:

- `environment()`, `putSiteEnvironment`, `putMercureEnvironment`, `serverEnvironment`, `withInputs`
- the `e environ` parameter of `drushCall`, `runtimePHPCall`, `siteDrush`, `openSite`, `siteExport`, `engineExport`, `engineCore` and `engineProject.drush`
- `openedSite.e` and `packagedStart.e`
- every `put` and `drop` call in `start.go` and `sitestart.go`

The `environ` type stays, private to `childenv.go`.

The refactor keeps today's output for every kind. `TestEnvKinds` rows are written from the current behavior first.

A wrong variable is diagnosed from the `Facts` of that launch and the emitted list, checked against `environment.txt`.

## Open ruling

An inherited `DRUPACK_ADMIN_PASSWORD` reaches Drush and export today. The server start drops it. Dropping it for every kind except `Operate` changes behavior, so it is left out of the refactor.

## Known cost

`Facts` has about 25 fields, and a field left unset compiles. `TestEnvDeclared` is the only guard.

# PRD: A loopback site answers on its own drupack.site name

## Problem Statement

Every Drupack site on a machine answers on `localhost`. Browsers key cookies by
host and ignore the port, so two sites share one cookie jar. Signing in to one
site signs the reader out of the other, and a session cookie from one site
reaches the other.

`*.localhost` would give each site a name, but it does not resolve outside
browsers. systemd-resolved answers `::1` alone, slim containers answer nothing,
and the macOS and Windows resolvers do not special-case it.

The owner holds `drupack.site`. Its public zone answers `*.drupack.site` with
`127.0.0.1`, as `*.ddev.site` does for DDEV.

The HTTPS work merged on main but is unreleased. The Drupack root's name
constraint permits `localhost` alone, so it must gain `drupack.site` before
beta8 ships it.

## Solution

A start on a `127.0.0.1` bind names the site `<label>.drupack.site`:

- a packed site takes its label from the `name` in `drupack.yml`, dots turned
  to dashes: `drupacked-demo.drupack.site`
- a folder served by the engine takes its folder name, lowercased, other
  characters turned to dashes: `~/DEV/My Shop` gives `my-shop.drupack.site`

The start looks the name up with the system resolver, for at most one second.
When the answer holds `127.0.0.1`, the printed URL, login link, `drush` and the
Listener record use the name. Otherwise they use `localhost` (packed site) or
the bind IP (engine), and one line says why. An explicit `--host` wins.

HTTP and HTTPS starts follow the same rule. The server answers the name,
`localhost`, `127.0.0.1` and `[::1]` in every case.

## User Stories

1. As a reader running two packed sites, I want each on its own host, so that signing in to one leaves my session on the other.
2. As a reader, I want the printed URL to open as printed, so that I never get a name my resolver cannot find.
3. As a reader offline, I want the start to fall back to `localhost`, so that the site still opens.
4. As a reader behind a DNS filter that drops private answers, I want the start to say so, so that I know why the URL says `localhost`.
5. As a reader, I want the start to wait at most one second on DNS, so that a dead resolver does not stall it.
6. As a reader who ran `trust`, I want HTTPS on the name without a warning, so that the padlock shows on `drupacked-demo.drupack.site`.
7. As an mkcert user, I want the certificate mkcert writes to cover the name, so that mkcert keeps working after the upgrade.
8. As a reader without `trust`, I want HTTP on the name, so that cookies stay apart before I trust anything.
9. As a reader, I want `--host localhost` to keep today's behaviour, so that I can opt out.
10. As a reader, I want `localhost` to keep answering beside the name, so that bookmarks and scripts keep working.
11. As a reader, I want `drush` and the login link on the host the start chose, so that the one-time link signs me in on the URL I use.
12. As a developer serving a folder with the engine, I want the folder name as the label, so that each project gets its own host.
13. As a developer whose folder settings restrict trusted hosts, I want the docs to name the pattern to add, so that Drupal accepts the name.
14. As a site author, I want the label derived from `name`, so that I set nothing new in `drupack.yml`.
15. As a reader binding a non-loopback address, I want today's behaviour, so that LAN access keeps its IP URL.
16. As a maintainer, I want the root's constraint to permit `drupack.site` and nothing else new, so that a stolen root key cannot sign other public names.
17. As a maintainer debugging "my URL says localhost", I want one command that prints the decision and its reason, so that I can reproduce it.
18. As a maintainer, I want the conformance suite to run the same on any resolver, so that CI and developer machines agree.

## Implementation Decisions

- D1. The runtime's `tls-source` word becomes the one decision point for host and TLS source. It takes `--bind B`, then `--name N` (packed) or `--folder F` (engine), and an optional `--host H`. It prints one JSON object: today's fields plus `host`, `alias` and `reason`. Evidence: `tls-source`, `runtime/entrypoint.go:349`; `tlsChoice()`, `application/process.php:300`.
- D2. Go derives the label. `--name`: dots become dashes. `--folder`: lowercase, any character outside `[a-z0-9-]` becomes `-`, dashes trimmed at both ends, cut to 63 characters. An empty result becomes `site`. `alias` is `<label>.drupack.site`. Evidence: the name pattern `^[a-z][a-z0-9.-]{0,31}$`, `launcher/internal/runtime/minted.go:11`.
- D3. The host order:
  1. `--host` is used as given, with no lookup.
  2. A bind other than `127.0.0.1` gives `localhost` (packed) or the bind IP (engine), with an empty reason.
  3. Otherwise `tls-source` looks up `alias` through Go's default resolver under a one-second context. An answer holding `127.0.0.1` gives `alias`. Any other outcome gives the fallback of step 2, with a reason.
  
  The public record is A only, so only a `127.0.0.1` bind is reachable by name. Evidence: every runtime build sets `CGO_ENABLED=1` with no `netgo` tag (`runtime/embed.sh:15`, `build/macos/build.sh:81`, `build/windows/build.ps1:165`), so Go uses the system resolver and the hosts file.
- D4. `reason` names the outcome in one clause: no answer, no answer within one second, or the addresses returned. The start prints `Name: <alias> did not resolve to 127.0.0.1 (<reason>), so the URL uses <host>.` Evidence: the start summary, `application/launch.php:895-908`.
- D5. `tls-source` decides HTTPS eligibility. A loopback bind with host `localhost`, `127.0.0.1`, `::1` or `alias` may serve HTTPS. Any other `--host` serves HTTP, as today. `TLS_HOSTS` and `tlsEligible()` leave `process.php`, and `tlsHint()` reads eligibility from the answer. Evidence: `application/process.php:296,319,360`.
- D6. `tlsSource` checks the root for the chosen host, which replaces the fixed `localhost`. Evidence: `verifies(certificate, key, roots, "localhost")`, `runtime/tls/tls.go:123`.
- D7. A new root's name constraint permits `localhost` and `drupack.site`, plus today's IP ranges. No code migrates an older root: none was released. A developer with one runs `trust --uninstall`, then `trust`. Evidence: `PermittedDNSDomains`, `runtime/tls/tls.go:79`.
- D8. mkcert writes its certificate for `localhost 127.0.0.1 ::1 *.drupack.site`. A certificate that does not verify for `alias` is not current, so the start writes it again. Evidence: `mkcertNames`, `runtime/tls/tls.go:37`; `current()`, `tls.go:347-367`.
- D9. Caddy serves `alias` as one more fixed address, `"https://{$DRUPACK_RUNTIME_ALIAS}:{$DRUPACK_RUNTIME_PORT}"`, in the Drupack and mkcert site snippets. The value is never empty and never `localhost`, so the address never collides. The HTTP snippet already answers any host. Evidence: `application/tls-drupack-site.caddy:3`, `application/tls-mkcert-site.caddy:2`, `application/tls-none-site.caddy:1`; quoted references, `tests/conformance/test_environment.py:110`.
- D10. `settings.php` trusts `DRUPACK_RUNTIME_ALIAS` beside `DRUPACK_RUNTIME_HOST`. A fallback start still answers the name for a reader whose browser resolves it. Evidence: `application/settings.php:41-46`.
- D11. `launch.php` drops the `localhost` default for `--host` and validates `--host` only when given. It calls `tls-source` before it exports `DRUPACK_RUNTIME_HOST`. The URL, `DRUSH_OPTIONS_URI`, the Listener record and `tlsHint` take the answer's `host`. `drush` keeps reusing the recorded host. Evidence: `application/launch.php:748,754-758,788,793,795,303`.
- D12. `engine/serve.php` passes `--folder` with the project's basename and uses the answer's `host` for its URL. `DRUPACK_RUNTIME_URL` keeps the probe host. Evidence: `engine/serve.php:186-188,202,240`.
- D13. `runtime/environment.txt` declares `DRUPACK_RUNTIME_ALIAS` and `DRUPACK_DNS_ANSWER`. `DRUPACK_DNS_ANSWER` replaces the lookup with a fixed outcome: a comma list of addresses, or `none`. The conformance harness sets it to `none` by default, as it empties `DRUPACK_TLS_DIR`. Evidence: `tests/conformance/harness.py:38`.
- D14. ADR 0037 records the name, the lookup and its fallback. `docs/cli.md` updates the `--host` default and the HTTPS host rule. `README.md` updates its localhost URLs. The engine docs name the trusted-host pattern `^.+\.drupack\.site$` for folders with their own settings. Evidence: `docs/cli.md:40,101-137`; `README.md:108,140,149`.

### Considered options

- PHP picks the host. `process.php` runs `gethostbynamel` in a child process killed after one second, then hands the host to `tls-source`. It lost because the decision splits across PHP and Go and costs one more process per start. Its Caddy part put the whole address list in one variable, which breaks the quoted-reference rule.
- Probe after start. No lookup in the start. Once the server answers, the readiness code requests the name and opens the browser on whichever host answered. It lost because the printed URL, login link, `drush` and the Listener record stay on `localhost`, against the owner's choice.
- Name plus a data-dir hash, and a new `drupack.yml` field for the label. The owner chose the existing `name` and the folder name.

## Testing Decisions

A good test drives the word or the start from outside and asserts what a reader
sees: the JSON answer, the printed lines, the served URL and certificate.

- Go unit tests, `runtime/tls/tls_test.go`. The host choice takes an injected
  lookup and runs table cases: `127.0.0.1`, `0.0.0.0`, a public address, no
  answer, a lookup past the deadline, bind `::1`, `--host`, and label edge
  names. `TestTheRootCannotVouchForAnotherSite` accepts `x.drupack.site` and
  still rejects `example.com`. The mkcert argument check gains `*.drupack.site`.
- PHP tests, `application/tests/launch_test.php`. A fake binary prints canned
  JSON. The tests assert the URL, the exported variables, the Listener record
  and the reason line. Prior art: the TLS choice cases at lines 217-240.
- Conformance, `tests/conformance/tls_cases.py`. The openssl test root at line
  24 gains `DNS:drupack.site`. A case with `DRUPACK_DNS_ANSWER=127.0.0.1` asserts
  the named URL, and requests it with the name mapped to `127.0.0.1`. The
  offline container asserts the fallback line through the real resolver.

## Out of Scope

- The Windows `trust` hand check, deferred by the owner.
- An AAAA record for `*.drupack.site`, and names for `::1` or other loopback binds.
- A domain other than `drupack.site`.
- The Mercure hub's address, which stays on the bind IP.
- Injecting trusted hosts into an engine folder's own settings.
- Migrating a root made before this change.

## Further Notes

- On the owner's machine the local resolver answers `0.0.0.0` for
  `*.drupack.site` while `1.1.1.1` and `ns11.infomaniak.ch` answer
  `127.0.0.1`. That machine takes the fallback branch.
- hstspreload.org lists neither `site` nor `drupack.site`, so browsers allow
  plain HTTP on the name.
- The name constraint ships once, in beta8.

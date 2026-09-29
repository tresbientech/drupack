# PRD: Agent Access in Mercury Demo

Source: design settled on 2026-09-29. Lands on the branch of the WordPal in
Mercury Demo PRD and merges with it.

## Problem Statement

A reader who wants an AI agent to work on a Drupal site assembles Simple OAuth,
Tool API and MCP Server. They then generate OAuth keys, save the key paths and
repair a registration endpoint by hand. Mercury Demo already ships `mcp_server`
in its code base and enables none of it.

## Solution

Mercury Demo applies the Agent Access recipe. Each site generates its own OAuth
key pair on its first start. A reader starts the demo, adds the demo's address
followed by `/mcp` to an agent, and signs in as the administrator.

## User Stories

1. As a reader, I want Agent Access applied on a fresh demo, so that my first start serves an MCP endpoint.
2. As a reader, I want OAuth keys generated on my first start, so that I run no key command.
3. As a reader, I want my site's keys to differ from every other demo's keys, so that a token another demo signed never opens my site.
4. As a reader, I want my keys kept across restarts, so that my agent's tokens stay valid.
5. As a reader, I want my keys kept across a demo upgrade, so that a new release does not disconnect my agent.
6. As a reader, I want the authorization metadata to name the host I browse, so that my agent registers against the demo and not `localhost` from the build.
7. As a reader, I want `/mcp` to refuse a request without a token, so that the endpoint exposes nothing to an unauthenticated caller.
8. As a reader, I want to connect as the administrator I created on the first start, so that I grant no permission before trying it.
9. As a reader, I want the README to name the MCP address and the sign-in step, so that I can connect an agent without reading Agent Access's own docs.
10. As a reader, I want the README to say some agents refuse a plain HTTP address, so that a refusal is expected.
11. As a reader of a demo installed before this release, I want the README to name the one command that applies Agent Access, so that I can connect too.
12. As a reader who never connects an agent, I want the demo to serve my site unchanged, so that Agent Access costs me only one key generation.
13. As a reader on Linux, macOS or Windows, I want the keys generated the same way, so that every platform can connect.
14. As a maintainer, I want Agent Access pinned to one release, so that a new alpha changes the demo only when I choose.
15. As a maintainer, I want a site test that checks the endpoint, the metadata and the keys, so that a regression stops the release.
16. As a maintainer, I want the Seed site to carry no key, so that no two sites can share one.

## Implementation Decisions

Composer:

- The demo requires `drupal/agent_access` from packages.drupal.org, pinned to
  1.0.0-alpha2.
- Root stability flags admit the alpha and beta packages the recipe needs.
  Minimum stability stays `stable`.

Site recipe:

- The site recipe applies `agent_access` beside `mercury_demo`, so the Seed site
  carries its modules, scopes and tools.

Keys:

- The demo's site settings file points Simple OAuth at a key directory in Site
  data. The directory sits outside the private files directory, which Drupal
  serves through `/system/files`.
- When that directory is missing, the settings file generates an RSA pair into a
  temporary directory and renames it into place. A start that loses the rename
  discards its pair, so the two keys always match.
- Key files are readable by their owner only.
- The check costs one directory test per bootstrap. The first bootstrap is a
  Drush step of the first start.

Registration endpoint:

- The site settings file clears the stored registration endpoint, so Simple
  OAuth derives it from each request's host.

Access:

- The recipe creates no role and grants no permission. The administrator holds
  every permission and connects as themselves.

Backlog:

- The entry on Mercury's MCP packages narrows to `mcp_tools`, which the demo
  still ships without enabling.

## Testing Decisions

- A good test drives the executable the way a reader does and checks what an
  agent sees over HTTP.
- A site test in the demo's own test directory starts the demo and checks:
  - the authorization server metadata answers 200, and its registration
    endpoint names the served host;
  - `/mcp` answers 401 without a token;
  - two Site data directories publish different keys in their JWKS;
  - a restart publishes the same key.
- The test needs no network access, since the build applies the recipe.
- Prior art: the demo's `WordPalConversion` case and its other site tests.

## Out of Scope

- HTTPS on the demo's listener.
- A dedicated agent role or any permission grant.
- A generic first-start hook in the engine.
- Key rotation.
- Removing `mcp_tools` from the demo.
- Agent Access for sites other than Mercury Demo.

## Further Notes

- Every package Agent Access adds is alpha or beta, and the recipe applies with
  strict config. A later release may conflict with Mercury's own config.
- OAuth 2.1 allows plain HTTP on a loopback address. Agent Access's docs ask for
  HTTPS, and an agent may enforce that.
- The WordPal branch waits on a WordPal release, so this change ships with it.

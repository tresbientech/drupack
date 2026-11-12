# Agents

Rules for an LLM agent that works in this repository. `CONTRIBUTING.md` holds the build, the tests and the release, and applies to agents and people alike.

## Read first

- `CONTEXT.md` for the vocabulary
- `CONTRIBUTING.md` for the build and the tests
- `docs/adr/` for the decisions behind the current shape

## Issues

- The [drupal.org issue queue](https://git.drupalcode.org/project/drupack/-/work_items) holds the open questions, the follow-ups, the plans and the specs.
- A subject of work starts with an issue. Search the queue before a new one.
- An issue an agent writes carries the `LLM` label, one `category::` label, one `state::` label and one `priority::` label.
- Its body follows the `Default` template. `## AI disclosure` comes first and reads "Generated with the help of an LLM."
- The body states the problem and the target in under 100 words, with no first person.
- A person reads the text before it is posted, and answers for it.

## Records

- The repository holds no work state: no backlog, plan, spec, follow-up list or status file.
- A settled decision is a numbered ADR in `docs/adr/`.

## Commits

A commit for an issue uses Drupal core's subject:

```
fix: #3636009 Refuse the options drush cannot use
```

- The type is `fix`, `feat`, `task`, `refactor`, `perf`, `test`, `docs`, `ci` or `chore`.
- One issue gives one commit on `main`.
- A commit that belongs to no issue keeps a plain imperative subject.

## Merge path

- The canonical repository is `project/drupack` on drupal.org. GitHub holds a copy that builds and publishes, and takes no pull requests.
- A merge request on the issue's fork runs the unit tests. A maintainer squash-merges it on drupal.org.
- `.github/workflows/upstream.yml` pushes every version tag.

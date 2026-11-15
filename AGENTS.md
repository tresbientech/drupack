# Agents

Rules for an LLM agent that works in this repository. `CONTRIBUTING.md` applies to agents and people alike, and comes first.

## Issues

- An issue an agent writes carries the `LLM` label, one `category::` label, one `state::` label and one `priority::` label.
- Its body follows the `Default` template. `## AI disclosure` comes first and reads "Generated with the help of an LLM."
- The body states the problem and the target in under 100 words, with no first person.
- A person reads the text before it is posted, and answers for it.
- An agent that starts work on an issue assigns the issue to its account.
- The `state::` label follows the work. It is `state::needsReview` once the merge request is open, and `state::fixed` once it is merged.

## Records

- The repository holds no work state: no backlog, plan, spec, follow-up list or status file.
- A settled decision is a numbered ADR in `docs/adr/`.

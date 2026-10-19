# A first start's steps are typed

Accepted on 2026-10-20.

## Context

`SiteData::steps()` returned bare step names, spelled about twenty times across
`SiteData.php` and `launch.php`. Four functions each derived a precondition
from the list. A progress file naming a step the release did not know failed in
`runStep`, after the earlier steps had run.

## Decision

- `Step` is a string-backed enum. Its values are the names the progress file
  records, so the file's format is unchanged.
- `Step::needs()` names what each step needs: `Need::Recipe`,
  `Need::Connection` or `Need::Credentials`. `Step::owed()` merges the needs of
  a list of steps.
- `requireNeeds()` in `launch.php` holds the refusals, since they name
  command-line options. A start generates the credentials instead.
- `SiteData::steps()` refuses a progress file that names an unknown step, before
  any step runs.

## Consequences

- A new step needs a case and an arm in `report()`, `needs()` and `runStep`.
- A step renamed between releases makes an older progress file unreadable.
  Removing the file lets the start check the site again.

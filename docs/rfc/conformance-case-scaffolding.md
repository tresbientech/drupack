# Let the case base class do the case setup

Proposed on 2026-09-21. Not implemented.

## The question

`harness.ConformanceCase` is 15 lines and gates a class on its platform and its tools. Every case class then writes six more lines by hand to get a directory to write into. What should the base class do instead?

## What ships today

`harness.py:472-486` holds the whole base class. It reads two class attributes and raises `SkipTest` or `RuntimeError`.

Ten case modules then repeat this, 19 times:

```python
@classmethod
def setUpClass(cls):
    super().setUpClass()
    cls.class_dir = harness.RESULTS / cls.__name__
    cls.class_dir.mkdir(parents=True, exist_ok=True)

def setUp(self):
    self.case_dir = self.class_dir / self._testMethodName
    self.case_dir.mkdir(parents=True, exist_ok=True)
```

The `case_dir` half appears 17 times. `CacheRootFull` omits it, because it holds one method.

Two modules build the same recording browser opener: `browser_cases.py:18-24` and `handover_cases.py:15-26`. Both call `harness.install_recorder()`, then both rebuild the same `PATH` prepend, because `install_recorder` stops one line short.

`test_harness.py` tests the harness itself. Its 6 classes leave `Site` and `ConformanceCase` untested, which are the two pieces every module depends on. The suite never discovers it: `__main__.py:27` globs `*_cases.py`. CI runs it in the Linux job alone (`release.yml:70`), so the Windows branch of `stop_process` is mocked on Linux and never runs anywhere.

## Why this decision is worth making

`docs/plans/test-architecture-followups.md` already names three of these as worth doing:

- An unmarked case class skips on every platform in silence. `PLATFORMS` defaults to empty, so a class that declares none, or misspells a constant, runs nowhere while the suite stays green.
- A class that forgets to call the base `setUpClass` skips its platform and tool gate entirely.
- A container log lands in its class's results directory rather than its case's.

All three exist because the base class hands out nothing. A subclass has to write `setUpClass` to get a directory, and a subclass that writes `setUpClass` can forget `super()`. The silent skip and the forgotten gate share one cause.

This RFC implements that part of the follow-up list. It changes nothing the suite proves.

## Decision

Widen `ConformanceCase` so no case writes `setUpClass` for a directory.

```python
class ConformanceCase(unittest.TestCase):
    PLATFORMS = ()
    TOOLS = ()

    # Raises at import when PLATFORMS is empty or names an unknown platform,
    # so a class can no longer skip everywhere in silence.
    def __init_subclass__(cls, **kwargs): ...

    # results/<ClassName> and results/<ClassName>/<method>, made on first read.
    # Lazy, so a subclass needs no setUpClass to receive them.
    @property
    def case_dir(self) -> Path: ...
    @classmethod
    def class_directory(cls) -> Path: ...

    # A product process under this case's directory, stopped when the case ends.
    def site(self, name: str = "data", **start) -> harness.Site: ...

    # An environment with a recording browser opener ahead of PATH.
    def recorder_env(self, **extra) -> dict: ...
```

### How a case uses it

```python
class BrowserOpenCases(harness.ConformanceCase):
    PLATFORMS = (harness.LINUX, harness.MACOS)

    def test_later_start_opens_browser_on_dashboard(self):
        site = self.site(env=self.recorder_env())
        site.start(terminal=True)
        ...
```

No `setUpClass`. No `setUp`. No `addCleanup(site.stop)`. No `PATH` rebuild.

### What the base class hides

- The results directory layout, and the rule that a case's artifacts belong under its own method's directory.
- The platform and tool gate, which now runs whether or not a subclass overrides `setUpClass`.
- The `Site` lifetime, so a case that fails mid-test still stops its server.
- The recorder install and the `PATH` prepend, which today live in two modules.

### What stays outside

The Docker container lifecycle. `initialization_cases.py:523-570` and `server_database_cases.py:34-78` run the same poll-and-remove shape line for line, and 33 raw `docker` calls sit across five modules. That belongs to a maintained package rather than to this base class. Surveyed separately in [container-lifecycle-and-testcontainers.md](container-lifecycle-and-testcontainers.md), which decides to consolidate here first.

## Dependency strategy

In-process for the directories and the gate. Local-substitutable for `site()`, which binds a real port that the operating system picks.

## Tests

`test_harness.py` gains the cases. The table maps what changes.

| Behaviour | Today | After |
|---|---|---|
| Results directory per class and per method | 19 hand-written copies, untested | One implementation, two tests in `test_harness.py` |
| Platform gate | `ConformanceCaseTest` does not exist | A subclass declaring an unmatched platform skips; a subclass declaring the current one runs |
| Empty `PLATFORMS` | Skips silently on every platform | Raises at class creation, with a test asserting the raise |
| Misspelled platform constant | Skips silently | Raises at class creation, with a test asserting the raise |
| Tool gate | Untested | A subclass declaring a missing tool raises `RuntimeError` |
| `Site` stop on failure | Each case calls `stop()` in its own `tearDown` or not at all | `site()` registers the cleanup; a test asserts a failing case still stops its process |
| Recorder environment | Two module-private copies | One method, with a test asserting the opener name leads `PATH` |

No conformance case is deleted. This RFC moves scaffolding, so every `*_cases.py` case keeps its name and its assertions. The 19 `setUpClass` bodies and 17 `setUp` bodies go, one module per commit, and the suite stays green between each.

Two follow-up items close on the way:

- A container log lands under `self.case_dir`, because that is the only directory a case now receives.
- `Site.start` opens its log with `ab`, so a second start on one `Site` keeps the first start's output. `site_cases.py` restarts one `Site` on purpose.

## Consequences

- A new case declares `PLATFORMS` and writes a test method. Nothing else.
- A misspelled platform constant fails at import, on every platform, instead of passing green.
- `harness.py` grows by about 40 lines. Ten case modules lose about 190.
- `test_harness.py` covers `ConformanceCase` and the `Site` lifetime, which today no test reaches.
- `test_harness.py` still runs only in the Linux job. This RFC does not change that, and the Windows `stop_process` branch stays mocked.

## Considered options

- Move the suite to pytest and use fixtures for the directory, the site and the recorder. The fixtures are better than a base class. The port costs ten modules of rewriting, plus a new dependency on a suite four days old.
- Keep the base class and add a lint that every subclass calls `super().setUpClass()`. It catches the forgotten gate and leaves the 36 copied blocks in place.
- A metaclass that wraps `setUpClass`. It closes the forgotten-super hole completely. It also adds framework machinery to a suite that aims to put a Windows case beside its Linux twin.
- Leave it. The follow-up list says worth doing, and each new case module copies the block again.

## Order of work

1. `__init_subclass__` and the lazy directories in `harness.py`, with their `test_harness.py` cases. No case module changes, because the hand-written `setUpClass` still wins.
2. `site()` and `recorder_env()`, with their cases.
3. One commit per case module removing the scaffolding. Ten commits, each green.
4. The `ab` log mode and the container log path, from the follow-up list.
5. Strike the closed items from `docs/plans/test-architecture-followups.md`.

Step 1 stands alone and is reviewable without a suite run.

## Out of scope

- The Docker container lifecycle, in [container-lifecycle-and-testcontainers.md](container-lifecycle-and-testcontainers.md).
- The wait table, which the suite already owns and `test_harness.py` already checks.
- Running `test_harness.py` on Windows and macOS, which is a workflow change.
- The other follow-up items, which are not about scaffolding.

"""build/release/release-plan.py against responses recorded from the GitHub REST API.
Run with:

    python3 -m unittest discover -s tests/conformance -p test_release_plan.py
"""

import copy
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("release_plan", ROOT / "build" / "release" / "release-plan.py")
release_plan = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release_plan)

TARGETS = json.loads((ROOT / "launcher" / "internal" / "payload" / "targets.json").read_text())
RECORDED = ROOT / "tests" / "conformance" / "release_plan"
RUNS = json.loads((RECORDED / "runs.json").read_text())
ARTIFACTS = json.loads((RECORDED / "artifacts.json").read_text())
COMPARE = json.loads((RECORDED / "compare.json").read_text())

REPO = "tresbientech/drupack"
SHA = "c52fcdd2c7c407f5d1cc3c7803a8600972df8073"
BEFORE = "8edd52f95db36de85676fee5e196996da9b8fbe0"
VERSION = "1.0.0-beta15"
CANDIDATE = RUNS["workflow_runs"][0]["id"]
RUNS_PATH = f"repos/{REPO}/actions/workflows/release.yml/runs?head_sha={SHA}&event=workflow_dispatch&status=success"
ARTIFACTS_PATH = f"repos/{REPO}/actions/runs/{CANDIDATE}/artifacts?per_page=100"
COMPARE_PATH = f"repos/{REPO}/compare/{BEFORE}...{SHA}"

GLIBC = {"builder": "gnu", "libc": "glibc", "suffix": ""}
MUSL = {"builder": "musl", "libc": "musl", "suffix": "-musl"}


def runtime(arch, libc):
    return {"name": f"Linux {arch} {libc['libc']} runtime", "system": "linux", "arch": arch,
            "target": f"linux-{arch}{libc['suffix']}", "libc": libc["libc"], "builder": libc["builder"],
            "runner": {"amd64": "ubuntu-24.04", "arm64": "ubuntu-24.04-arm"}[arch], "timeout": 180}


def dispatch(**fields):
    return event(event_name="workflow_dispatch", **fields)


def event(**fields):
    base = {"ref_type": "branch", "ref_name": "main", "event_name": "push", "sha": SHA,
            "before": BEFORE, "platform": "all", "version": "", "repo": REPO, "suite": ""}
    return {**base, **fields}


TAG = event(ref_type="tag", ref_name=VERSION, before="0" * 40)
# Every push asks for a candidate first; these responses find none.
NO_RUN = {RUNS_PATH: {"workflow_runs": []}}


def changed(*names):
    return {"files": [{"filename": name} for name in names]}


def with_expired(flags, name="drupack-linux-amd64"):
    return {"artifacts": [{"name": name, "expired": flag} for flag in flags]}


class Unscripted(Exception): ...  # a path the test did not script


class Row(unittest.TestCase):
    def run_plan(self, event, responses={}):
        """The plan for event, with the paths fetched kept in self.fetched."""
        self.fetched = []

        def fetch(path):
            self.fetched.append(path)
            if path not in responses:
                raise Unscripted(path)
            return copy.deepcopy(responses[path])

        return release_plan.plan(event, TARGETS, fetch)

    def assertNotBuilding(self, result):
        self.assertFalse(result["build"])
        self.assertEqual(([[]] * 4, ""), ([result[key] for key in ("unit", "runtime", "engine", "tests")], result["platforms"]))


class Tag(Row):
    def test_a_tag_picks_its_candidate_from_the_recorded_runs(self):
        both_fetches = [RUNS_PATH, ARTIFACTS_PATH]
        rows = {
            "no candidate": (TAG, NO_RUN, None, [RUNS_PATH]),
            "a live candidate": (TAG, {RUNS_PATH: RUNS, ARTIFACTS_PATH: ARTIFACTS}, CANDIDATE, both_fetches),
            "an expired candidate": (TAG, {RUNS_PATH: RUNS, ARTIFACTS_PATH: with_expired([False, True])}, None, both_fetches),
            "an expired builder archive keeps the candidate":
                (TAG, {RUNS_PATH: RUNS, ARTIFACTS_PATH: with_expired([True], "builder-linux-arm64-gnu")}, CANDIDATE, both_fetches),
            "a run of another title fetches no artifacts": (TAG, {RUNS_PATH: {"workflow_runs": RUNS["workflow_runs"][1:]}}, None, [RUNS_PATH]),
            "an empty artifact list": (TAG, {RUNS_PATH: RUNS, ARTIFACTS_PATH: with_expired([])}, CANDIDATE, both_fetches),
        }
        for label, (tag, responses, candidate, fetched) in rows.items():
            with self.subTest(label):
                result = self.run_plan(tag, responses)
                self.assertEqual((tag["ref_name"], "sha", candidate), (result["version"], result["image_tags"], result["candidate"]))
                self.assertEqual(fetched, self.fetched)
                if candidate is None:
                    self.assertTrue(result["build"])
                    self.assertEqual(ALL, result["platforms"])
                else:
                    self.assertNotBuilding(result)

    def test_the_first_matching_run_is_the_candidate(self):
        first, second = RUNS["workflow_runs"][1], RUNS["workflow_runs"][0]
        runs = {"workflow_runs": [first, second, {"id": 7, "display_title": second["display_title"]}]}
        result = self.run_plan(TAG, {RUNS_PATH: runs, ARTIFACTS_PATH: ARTIFACTS})
        self.assertEqual(CANDIDATE, result["candidate"])


class Dispatch(Row):
    def test_a_dispatch_plans_without_a_fetch(self):
        rows = {
            "a named dispatch on all builds every platform": (dispatch(version="2.0.0"), "2.0.0", "dev sha", ALL),
            "a named dispatch on one platform builds it alone": (dispatch(version="2.0.0", platform="macos-arm64"), "2.0.0", "dev", "macos-arm64"),
            "an unnamed dispatch takes the commit for its version": (dispatch(before=""), "dev-" + SHA[:12], "dev", ALL),
        }
        for label, (dispatched, version, image_tags, platforms) in rows.items():
            with self.subTest(label):
                result = self.run_plan(dispatched)
                self.assertEqual((version, image_tags, None, True), (result["version"], result["image_tags"], result["candidate"], result["build"]))
                self.assertEqual(platforms, result["platforms"])
                self.assertEqual([], self.fetched)


class Push(Row):
    def test_a_main_push_reuses_a_live_candidate_and_compares_otherwise(self):
        rows = {
            "a live candidate": ({RUNS_PATH: RUNS, ARTIFACTS_PATH: ARTIFACTS}, CANDIDATE, [RUNS_PATH, ARTIFACTS_PATH]),
            "an expired candidate": ({RUNS_PATH: RUNS, ARTIFACTS_PATH: with_expired([True]), COMPARE_PATH: changed("build/release/release-plan.py")},
                                     None, [RUNS_PATH, ARTIFACTS_PATH, COMPARE_PATH]),
            "no candidate": ({**NO_RUN, COMPARE_PATH: changed("build/release/release-plan.py")}, None, [RUNS_PATH, COMPARE_PATH]),
        }
        for label, (responses, candidate, fetched) in rows.items():
            with self.subTest(label):
                result = self.run_plan(event(), responses)
                self.assertEqual(("dev-" + SHA[:12], "dev", candidate), (result["version"], result["image_tags"], result["candidate"]))
                self.assertEqual(fetched, self.fetched)
                if candidate is None:
                    self.assertEqual("linux-amd64", result["platforms"])
                else:
                    self.assertNotBuilding(result)

    def test_a_new_branch_builds_linux_amd64_without_a_compare(self):
        result = self.run_plan(event(before="0" * 40), NO_RUN)
        self.assertEqual(("linux-amd64", [runtime("amd64", GLIBC), runtime("amd64", MUSL)], True),
                         (result["platforms"], result["runtime"], result["build"]))
        self.assertEqual("dev-" + SHA[:12], result["version"])
        self.assertEqual([RUNS_PATH], self.fetched)

    def test_the_compare_decides_whether_a_push_builds(self):
        rows = {
            "documentation alone": (COMPARE, False),
            "a nested readme": (changed("docs/a.md", "launcher/README.md"), True),
            "a license and a top-level markdown file": (changed("LICENSE", "README.md", "docs/x/y.png"), False),
            "a code file": (changed("README.md", "build/test/qa.sh"), True),
            "an empty compare": (changed(), False),
        }
        for label, (files, builds) in rows.items():
            with self.subTest(label):
                result = self.run_plan(event(), {**NO_RUN, COMPARE_PATH: files})
                self.assertEqual([RUNS_PATH, COMPARE_PATH], self.fetched)
                if builds:
                    self.assertEqual("linux-amd64", result["platforms"])
                else:
                    self.assertNotBuilding(result)


class LinuxRuntime(Row):
    def test_the_runtime_rows_follow_the_build_and_add_the_amd64_musl_payload(self):
        for label, (dispatched, expected) in {
            "an arm64 build adds the payload row": (dispatch(platform="linux-arm64"), [runtime("amd64", MUSL), runtime("arm64", GLIBC), runtime("arm64", MUSL)]),
            "an amd64 build has no duplicate row": (event(before="0" * 40), [runtime("amd64", GLIBC), runtime("amd64", MUSL)]),
            "a macos-only build still compiles the payload": (dispatch(platform="macos-arm64"), [runtime("amd64", MUSL)]),
        }.items():
            with self.subTest(label):
                self.assertEqual(expected, [row for row in self.run_plan(dispatched, NO_RUN)["runtime"] if row["system"] == "linux"])

    def test_every_platform_has_a_runtime_row_on_its_own_runner(self):
        native = {"libc": "", "builder": ""}
        self.assertEqual(
            [runtime("amd64", GLIBC), runtime("amd64", MUSL), runtime("arm64", GLIBC), runtime("arm64", MUSL),
             {"name": "macOS arm64 runtime", "system": "macos", "arch": "arm64", "target": "macos-arm64",
              **native, "runner": "macos-15", "timeout": 240},
             {"name": "Windows amd64 runtime", "system": "windows", "arch": "amd64", "target": "windows-amd64",
              **native, "runner": "windows-2025", "timeout": 90}],
            self.run_plan(dispatch(), NO_RUN)["runtime"])

    def test_no_build_has_no_runtime_row(self):
        self.assertEqual([], self.run_plan(event(), {**NO_RUN, COMPARE_PATH: COMPARE})["runtime"])


def row(name, runner, target, results, shard="", smoke=False, variant=False, first=False):
    return {"name": name, "runner": runner, "target": target, "shard": shard, "smoke": smoke, "variant": variant,
            "first": first, "results": results}


LINUX_RUNNERS = {"amd64": "ubuntu-24.04", "arm64": "ubuntu-24.04-arm"}


def linux_rows(arch):
    return [row(f"Linux {arch} tests ({i}/2)", LINUX_RUNNERS[arch], f"linux-{arch}", f"linux-{arch}-{i}", shard=f"{i}/2",
                first=i == 1) for i in (1, 2)]


ARM64_SMOKE = row("Linux arm64 start cases", "ubuntu-24.04-arm", "linux-arm64", "linux-arm64-smoke", smoke=True, first=True)
MACOS = [row(f"macOS arm64 tests ({i}/2)", "macos-15", "macos-arm64", f"macos-arm64-{i}", shard=f"{i}/2+extra", variant=i == 2)
         for i in (1, 2)]


def windows_rows(runner, arch, label, extra):
    return [row(f"Windows {label}{i}/5)", runner, "windows-amd64", f"windows-{arch}-{i}", shard=f"{i}/5{extra}",
                variant=extra != "" and i == 5) for i in (1, 2, 3, 4, 5)]


WINDOWS_AMD64 = windows_rows("windows-2025", "amd64", "amd64 tests (", "+extra")
WINDOWS_FULL = WINDOWS_AMD64 + windows_rows("windows-11-arm", "arm64", "arm64 tests (amd64 emulated, ", "") + [
    row("Windows arm64 variant tests (amd64 emulated)", "windows-11-arm", "windows-amd64", "windows-arm64-variant", variant=True)]
ALL = "linux-amd64,linux-arm64,macos-arm64,windows-amd64"


class UnitAndEngineRows(Row):
    def test_each_system_the_plan_builds_takes_one_unit_row_on_its_first_targets_runner(self):
        linux = {"system": "linux", "runner": "ubuntu-24.04"}
        macos = {"system": "macos", "runner": "macos-15"}
        windows = {"system": "windows", "runner": "windows-2025"}
        arm64 = {"system": "linux", "runner": "ubuntu-24.04-arm"}
        for platform, expected in {"all": [linux, macos, windows], "linux-arm64": [arm64], "macos-arm64": [macos]}.items():
            with self.subTest(platform):
                self.assertEqual(expected, self.run_plan(dispatch(platform=platform))["unit"])

    def test_each_planned_target_takes_one_engine_row_and_the_platforms_print_raw(self):
        result = self.run_plan(dispatch())
        self.assertEqual([{"target": target, "runner": runner} for target, runner in (
            ("linux-amd64", "ubuntu-24.04"), ("linux-arm64", "ubuntu-24.04-arm"), ("macos-arm64", "macos-15"),
            ("windows-amd64", "windows-2025"))], result["engine"])
        self.assertEqual(ALL, release_plan.encode("platforms", result["platforms"]))


class Tests(Row):
    def test_each_suite_and_platform_takes_its_rows(self):
        rows = {
            "full": (dispatch(suite="full"), linux_rows("amd64") + linux_rows("arm64") + MACOS + WINDOWS_FULL),
            "no suite": (dispatch(), linux_rows("amd64") + linux_rows("arm64") + MACOS + WINDOWS_FULL),
            "gate": (dispatch(suite="gate"), linux_rows("amd64") + [ARM64_SMOKE] + MACOS + WINDOWS_AMD64),
            "linux arm64": (dispatch(platform="linux-arm64"), linux_rows("arm64")),
            "macos under gate": (dispatch(platform="macos-arm64", suite="gate"), MACOS),
            "windows": (dispatch(platform="windows-amd64"), WINDOWS_FULL),
        }
        for label, (dispatched, expected) in rows.items():
            with self.subTest(label):
                self.assertEqual(expected, self.run_plan(dispatched)["tests"])

    def test_a_push_and_a_tag_test_in_full(self):
        rows = {"a push": (event(before="0" * 40), linux_rows("amd64")),
                "a tag": (TAG, linux_rows("amd64") + linux_rows("arm64") + MACOS + WINDOWS_FULL)}
        for label, (planned, expected) in rows.items():
            with self.subTest(label):
                self.assertEqual(expected, self.run_plan(planned, NO_RUN)["tests"])

    def test_the_schedule_tests_every_platform_in_full_without_a_fetch(self):
        result = self.run_plan(event(event_name="schedule", before=""))
        self.assertEqual(("dev-" + SHA[:12], "dev", None, ALL), (result["version"], result["image_tags"], result["candidate"], result["platforms"]))
        self.assertEqual(linux_rows("amd64") + linux_rows("arm64") + MACOS + WINDOWS_FULL, result["tests"])
        self.assertEqual([], self.fetched)


class Failure(Row):
    def test_a_failed_fetch_stops_the_plan(self):
        for label, planned, responses in (("compare", event(), NO_RUN), ("runs", TAG, {}), ("artifacts", TAG, {RUNS_PATH: RUNS})):
            with self.subTest(label), self.assertRaises(Unscripted):
                self.run_plan(planned, responses)

    def test_a_response_of_another_shape_is_refused_naming_its_path(self):
        title = RUNS["workflow_runs"][0]["display_title"]
        run = lambda **fields: {"workflow_runs": [{"id": 1, "display_title": title, **fields}]}
        rows = [(TAG, RUNS_PATH, "actions/workflows/release.yml/runs", {}, bad) for bad in (
            run(id="12"), run(id=True), run(id=None), run(display_title=None), {"workflow_runs": {"id": 1}})]
        rows += [(TAG, ARTIFACTS_PATH, "artifacts", {RUNS_PATH: RUNS}, bad) for bad in (
            {"artifacts": [{"expired": "false"}]}, {"artifacts": [{}]}, {"artifacts": "none"})]
        rows += [(event(), COMPARE_PATH, "compare", NO_RUN, bad) for bad in (
            {"files": [{"filename": 3}]}, {"files": [{}]}, {"files": None})]
        for planned, path, pattern, others, bad in rows:
            with self.subTest(path=path, body=bad), self.assertRaisesRegex(ValueError, pattern):
                self.run_plan(planned, {**others, path: bad})


if __name__ == "__main__":
    unittest.main()

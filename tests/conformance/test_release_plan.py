"""build/release-plan.py against responses recorded from the GitHub REST API.
Run with:

    python3 -m unittest discover -s tests/conformance -p test_release_plan.py
"""

import copy
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("release_plan", ROOT / "build" / "release-plan.py")
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

AMD64 = [{"arch": "amd64"}]
BOTH = AMD64 + [{"arch": "arm64"}]
GLIBC = {"builder": "gnu", "libc": "glibc", "suffix": ""}
MUSL = {"builder": "musl", "libc": "musl", "suffix": "-musl"}


def runtime(arch, libc):
    return {"arch": arch, **libc}


def dispatch(**fields):
    return event(event_name="workflow_dispatch", **fields)


def event(**fields):
    base = {"ref_type": "branch", "ref_name": "main", "event_name": "push", "sha": SHA,
            "before": BEFORE, "platform": "all", "version": "", "repo": REPO}
    return {**base, **fields}


TAG = event(ref_type="tag", ref_name=VERSION, before="0" * 40)


def changed(*names):
    return {"files": [{"filename": name} for name in names]}


def with_expired(flags):
    return {"artifacts": [{"expired": flag} for flag in flags]}


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
        self.assertEqual([[]] * 6, [result[key] for key in ("linux", "linux_tests", "linux_runtime", "macos", "macos_tests", "windows")])


class Tag(Row):
    def test_a_tag_picks_its_candidate_from_the_recorded_runs(self):
        other = event(ref_type="tag", ref_name="1.0.0-beta16")
        both_fetches = [RUNS_PATH, ARTIFACTS_PATH]
        rows = {
            "no candidate": (TAG, {RUNS_PATH: {"workflow_runs": []}}, None, [RUNS_PATH]),
            "a live candidate": (TAG, {RUNS_PATH: RUNS, ARTIFACTS_PATH: ARTIFACTS}, CANDIDATE, both_fetches),
            "an expired candidate": (TAG, {RUNS_PATH: RUNS, ARTIFACTS_PATH: with_expired([False, True])}, None, both_fetches),
            "no matching run fetches no artifacts": (other, {RUNS_PATH: RUNS}, None, [RUNS_PATH]),
            "an empty artifact list": (TAG, {RUNS_PATH: RUNS, ARTIFACTS_PATH: with_expired([])}, CANDIDATE, both_fetches),
        }
        for label, (tag, responses, candidate, fetched) in rows.items():
            with self.subTest(label):
                result = self.run_plan(tag, responses)
                self.assertEqual((tag["ref_name"], "sha", candidate), (result["version"], result["image_tags"], result["candidate"]))
                self.assertEqual(fetched, self.fetched)
                if candidate is None:
                    self.assertTrue(result["build"])
                    self.assertEqual((BOTH, BOTH, AMD64), (result["linux"], result["macos"], result["windows"]))
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
            "a named dispatch on all builds every platform": (dispatch(version="2.0.0"), "2.0.0", "dev sha", BOTH, BOTH, AMD64),
            "a named dispatch on one platform builds it alone": (dispatch(version="2.0.0", platform="macos-arm64"), "2.0.0", "dev", [], BOTH[1:], []),
            "an unnamed dispatch takes the commit for its version": (dispatch(before=""), "dev-" + SHA[:12], "dev", BOTH, BOTH, AMD64),
        }
        for label, (dispatched, version, image_tags, linux, macos, windows) in rows.items():
            with self.subTest(label):
                result = self.run_plan(dispatched)
                self.assertEqual((version, image_tags, None, True), (result["version"], result["image_tags"], result["candidate"], result["build"]))
                self.assertEqual((linux, macos, windows), (result["linux"], result["macos"], result["windows"]))
                self.assertEqual([], self.fetched)


class Push(Row):
    def test_a_new_branch_builds_linux_amd64_without_a_fetch(self):
        result = self.run_plan(event(before="0" * 40))
        self.assertEqual((AMD64, [], [], True), (result["linux"], result["macos"], result["windows"], result["build"]))
        self.assertEqual("dev-" + SHA[:12], result["version"])
        self.assertEqual([], self.fetched)

    def test_the_compare_decides_whether_a_push_builds(self):
        rows = {
            "documentation alone": (COMPARE, False),
            "a nested readme": (changed("docs/a.md", "launcher/README.md"), True),
            "a license and a top-level markdown file": (changed("LICENSE", "README.md", "docs/x/y.png"), False),
            "a code file": (changed("README.md", "build/qa.sh"), True),
            "an empty compare": (changed(), False),
        }
        for label, (files, builds) in rows.items():
            with self.subTest(label):
                result = self.run_plan(event(), {COMPARE_PATH: files})
                self.assertEqual([COMPARE_PATH], self.fetched)
                if builds:
                    self.assertEqual(AMD64, result["linux"])
                else:
                    self.assertNotBuilding(result)


class LinuxRuntime(Row):
    def test_the_runtime_rows_follow_the_build_and_add_the_amd64_musl_payload(self):
        for label, (dispatched, expected) in {
            "an arm64 build adds the payload row": (dispatch(platform="linux-arm64"), [runtime("amd64", MUSL), runtime("arm64", GLIBC), runtime("arm64", MUSL)]),
            "an amd64 build has no duplicate row": (event(before="0" * 40), [runtime("amd64", GLIBC), runtime("amd64", MUSL)]),
            "a macos-only build still compiles the payload": (dispatch(platform="macos-amd64"), [runtime("amd64", MUSL)]),
        }.items():
            with self.subTest(label):
                self.assertEqual(expected, self.run_plan(dispatched)["linux_runtime"])

    def test_no_build_has_no_runtime_row(self):
        self.assertEqual([], self.run_plan(event(), {COMPARE_PATH: COMPARE})["linux_runtime"])


class LinuxTests(Row):
    def test_an_architecture_takes_a_row_per_share_and_the_first_runs_the_extras(self):
        rows = {arch: [{"arch": arch, "shard": f"{i}/2", "first": i == 1} for i in (1, 2)] for arch in ("amd64", "arm64")}
        for platform, expected in {"linux-arm64": rows["arm64"], "all": rows["amd64"] + rows["arm64"]}.items():
            with self.subTest(platform):
                self.assertEqual(expected, self.run_plan(dispatch(platform=platform))["linux_tests"])


class MacosTests(Row):
    def test_an_architecture_takes_a_row_per_share_and_the_last_runs_the_variant(self):
        amd64 = [{"arch": "amd64", "shard": f"{i}/3", "results": f"macos-amd64-{i}", "variant": i == 3} for i in (1, 2, 3)]
        arm64 = [{"arch": "arm64", "shard": f"{i}/2", "results": f"macos-arm64-{i}", "variant": i == 2} for i in (1, 2)]
        for platform, expected in {"macos-amd64": amd64, "macos-arm64": arm64, "all": amd64 + arm64}.items():
            with self.subTest(platform):
                self.assertEqual(expected, self.run_plan(dispatch(platform=platform))["macos_tests"])


class Failure(Row):
    def test_a_failed_fetch_stops_the_plan(self):
        for label, planned, responses in (("compare", event(), {}), ("runs", TAG, {}), ("artifacts", TAG, {RUNS_PATH: RUNS})):
            with self.subTest(label), self.assertRaises(Unscripted):
                self.run_plan(planned, responses)

    def test_a_response_of_another_shape_is_refused_naming_its_path(self):
        title = RUNS["workflow_runs"][0]["display_title"]
        run = lambda **fields: {"workflow_runs": [{"id": 1, "display_title": title, **fields}]}
        rows = [(TAG, RUNS_PATH, "actions/workflows/release.yml/runs", {}, bad) for bad in (
            run(id="12"), run(id=True), run(id=None), run(display_title=None), {"workflow_runs": {"id": 1}})]
        rows += [(TAG, ARTIFACTS_PATH, "artifacts", {RUNS_PATH: RUNS}, bad) for bad in (
            {"artifacts": [{"expired": "false"}]}, {"artifacts": [{}]}, {"artifacts": "none"})]
        rows += [(event(), COMPARE_PATH, "compare", {}, bad) for bad in (
            {"files": [{"filename": 3}]}, {"files": [{}]}, {"files": None})]
        for planned, path, pattern, others, bad in rows:
            with self.subTest(path=path, body=bad), self.assertRaisesRegex(ValueError, pattern):
                self.run_plan(planned, {**others, path: bad})


if __name__ == "__main__":
    unittest.main()

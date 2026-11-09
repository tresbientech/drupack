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

TARGETS = json.loads((ROOT / "launcher" / "internal" / "runtime" / "targets.json").read_text())
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
ARM64 = [{"arch": "arm64"}]
GLIBC = {"builder": "gnu", "libc": "glibc", "suffix": ""}
MUSL = {"builder": "musl", "libc": "musl", "suffix": "-musl"}


def runtime(arch, libc):
    return {"arch": arch, **libc}


def event(**fields):
    base = {"ref_type": "branch", "ref_name": "main", "event_name": "push", "sha": SHA,
            "before": BEFORE, "platform": "all", "version": "", "repo": REPO}
    return {**base, **fields}


TAG = event(ref_type="tag", ref_name=VERSION, before="0" * 40)
DISPATCH = event(event_name="workflow_dispatch", before="")


def changed(*names):
    return {"files": [{"filename": name} for name in names]}


def with_expired(flags):
    return {"artifacts": [{"expired": flag} for flag in flags]}


class Row(unittest.TestCase):
    def run_plan(self, event, responses=None):
        """The plan for event, with the paths fetched kept in self.fetched."""
        responses = responses or {}
        self.fetched = []

        def fetch(path):
            self.fetched.append(path)
            return copy.deepcopy(responses[path])

        return release_plan.plan(event, TARGETS, fetch)

    def assertNotBuilding(self, result):
        self.assertFalse(result["build"])
        for key in ("linux", "linux_runtime", "macos", "macos_tests", "windows"):
            self.assertEqual([], result[key])


class Tag(Row):
    def test_a_tag_without_a_candidate_builds_every_platform(self):
        result = self.run_plan(TAG, {RUNS_PATH: {"workflow_runs": []}})
        self.assertEqual(VERSION, result["version"])
        self.assertEqual("sha", result["image_tags"])
        self.assertIsNone(result["candidate"])
        self.assertTrue(result["build"])
        self.assertEqual([{"arch": "amd64"}, {"arch": "arm64"}], result["linux"])
        self.assertEqual([{"arch": "amd64"}, {"arch": "arm64"}], result["macos"])
        self.assertEqual(AMD64, result["windows"])
        self.assertEqual([RUNS_PATH], self.fetched)

    def test_a_tag_with_a_live_candidate_publishes_its_files(self):
        result = self.run_plan(TAG, {RUNS_PATH: RUNS, ARTIFACTS_PATH: ARTIFACTS})
        self.assertEqual(CANDIDATE, result["candidate"])
        self.assertNotBuilding(result)
        self.assertEqual([RUNS_PATH, ARTIFACTS_PATH], self.fetched)

    def test_a_tag_with_an_expired_candidate_builds_again(self):
        result = self.run_plan(TAG, {RUNS_PATH: RUNS, ARTIFACTS_PATH: with_expired([False, True])})
        self.assertIsNone(result["candidate"])
        self.assertTrue(result["build"])
        self.assertEqual([{"arch": "amd64"}, {"arch": "arm64"}], result["linux"])
        self.assertEqual([RUNS_PATH, ARTIFACTS_PATH], self.fetched)

    def test_a_tag_with_no_matching_run_fetches_no_artifacts(self):
        other = event(ref_type="tag", ref_name="1.0.0-beta16")
        result = self.run_plan(other, {RUNS_PATH: RUNS})
        self.assertIsNone(result["candidate"])
        self.assertTrue(result["build"])
        self.assertEqual([RUNS_PATH], self.fetched)

    def test_an_empty_artifact_list_keeps_the_candidate(self):
        result = self.run_plan(TAG, {RUNS_PATH: RUNS, ARTIFACTS_PATH: with_expired([])})
        self.assertEqual(CANDIDATE, result["candidate"])
        self.assertNotBuilding(result)

    def test_the_first_matching_run_is_the_candidate(self):
        runs = {"workflow_runs": [RUNS["workflow_runs"][1], RUNS["workflow_runs"][0],
                                  {"id": 7, "display_title": RUNS["workflow_runs"][0]["display_title"]}]}
        result = self.run_plan(TAG, {RUNS_PATH: runs, ARTIFACTS_PATH: ARTIFACTS})
        self.assertEqual(CANDIDATE, result["candidate"])


class Dispatch(Row):
    def test_a_named_dispatch_on_all_builds_every_platform(self):
        result = self.run_plan(event(event_name="workflow_dispatch", version="2.0.0"))
        self.assertEqual("2.0.0", result["version"])
        self.assertEqual("dev sha", result["image_tags"])
        self.assertIsNone(result["candidate"])
        self.assertEqual([{"arch": "amd64"}, {"arch": "arm64"}], result["linux"])
        self.assertEqual(AMD64, result["windows"])
        self.assertEqual([], self.fetched)

    def test_a_named_dispatch_on_one_platform_builds_it_alone(self):
        result = self.run_plan(event(event_name="workflow_dispatch", version="2.0.0", platform="macos-arm64"))
        self.assertEqual("dev", result["image_tags"])
        self.assertEqual([], result["linux"])
        self.assertEqual(ARM64, result["macos"])
        self.assertEqual([], result["windows"])
        self.assertEqual([], self.fetched)

    def test_an_unnamed_dispatch_takes_the_commit_for_its_version(self):
        result = self.run_plan(DISPATCH)
        self.assertEqual("dev-" + SHA[:12], result["version"])
        self.assertEqual("dev", result["image_tags"])
        self.assertTrue(result["build"])
        self.assertEqual([], self.fetched)


class Push(Row):
    def test_a_new_branch_builds_linux_amd64_without_a_fetch(self):
        result = self.run_plan(event(before="0" * 40))
        self.assertEqual(AMD64, result["linux"])
        self.assertEqual([], result["macos"])
        self.assertEqual([], result["windows"])
        self.assertTrue(result["build"])
        self.assertEqual("dev-" + SHA[:12], result["version"])
        self.assertEqual([], self.fetched)

    def test_a_documentation_only_push_builds_nothing(self):
        result = self.run_plan(event(), {COMPARE_PATH: COMPARE})
        self.assertNotBuilding(result)
        self.assertEqual([COMPARE_PATH], self.fetched)

    def test_a_nested_readme_builds(self):
        result = self.run_plan(event(), {COMPARE_PATH: changed("docs/a.md", "launcher/README.md")})
        self.assertEqual(AMD64, result["linux"])

    def test_a_license_and_a_top_level_markdown_file_build_nothing(self):
        result = self.run_plan(event(), {COMPARE_PATH: changed("LICENSE", "README.md", "docs/x/y.png")})
        self.assertNotBuilding(result)

    def test_a_code_file_builds(self):
        result = self.run_plan(event(), {COMPARE_PATH: changed("README.md", "build/qa.sh")})
        self.assertEqual(AMD64, result["linux"])

    def test_an_empty_compare_builds_nothing(self):
        self.assertNotBuilding(self.run_plan(event(), {COMPARE_PATH: changed()}))


class LinuxRuntime(Row):
    def test_an_arm64_build_adds_the_amd64_musl_payload_row(self):
        result = self.run_plan(event(event_name="workflow_dispatch", platform="linux-arm64"))
        self.assertEqual([runtime("amd64", MUSL), runtime("arm64", GLIBC), runtime("arm64", MUSL)],
                         result["linux_runtime"])

    def test_an_amd64_build_has_no_duplicate_row(self):
        result = self.run_plan(event(before="0" * 40))
        self.assertEqual([runtime("amd64", GLIBC), runtime("amd64", MUSL)], result["linux_runtime"])

    def test_a_macos_only_build_still_compiles_the_amd64_musl_payload(self):
        result = self.run_plan(event(event_name="workflow_dispatch", platform="macos-amd64"))
        self.assertEqual([runtime("amd64", MUSL)], result["linux_runtime"])

    def test_no_build_has_no_runtime_row(self):
        result = self.run_plan(event(), {COMPARE_PATH: COMPARE})
        self.assertEqual([], result["linux_runtime"])


class MacosTests(Row):
    def test_amd64_takes_four_shards_and_the_last_runs_the_variant(self):
        result = self.run_plan(event(event_name="workflow_dispatch", platform="macos-amd64"))
        self.assertEqual([{"arch": "amd64", "shard": f"{index}/4", "results": f"macos-amd64-{index}",
                           "variant": index == 4} for index in range(1, 5)], result["macos_tests"])

    def test_arm64_takes_one_shard_with_the_variant(self):
        result = self.run_plan(event(event_name="workflow_dispatch", platform="macos-arm64"))
        self.assertEqual([{"arch": "arm64", "shard": "1/1", "results": "macos-arm64-1", "variant": True}],
                         result["macos_tests"])

    def test_both_architectures_list_amd64_first(self):
        result = self.run_plan(DISPATCH)
        self.assertEqual(["macos-amd64-1", "macos-amd64-2", "macos-amd64-3", "macos-amd64-4", "macos-arm64-1"],
                         [row["results"] for row in result["macos_tests"]])


class Failure(Row):
    def test_a_failed_fetch_stops_the_plan(self):
        with self.assertRaises(KeyError):
            self.run_plan(event(), {})

    def test_a_failed_candidate_lookup_stops_the_plan(self):
        with self.assertRaises(KeyError):
            self.run_plan(TAG, {})

    def test_a_run_without_an_integer_id_is_refused(self):
        for bad in ("12", True, None):
            runs = {"workflow_runs": [{"id": bad, "display_title": RUNS["workflow_runs"][0]["display_title"]}]}
            with self.subTest(id=bad), self.assertRaisesRegex(ValueError, "actions/workflows/release.yml/runs"):
                self.run_plan(TAG, {RUNS_PATH: runs})

    def test_a_run_without_a_string_title_is_refused(self):
        with self.assertRaisesRegex(ValueError, "actions/workflows/release.yml/runs"):
            self.run_plan(TAG, {RUNS_PATH: {"workflow_runs": [{"id": 1, "display_title": None}]}})

    def test_runs_that_are_not_a_list_are_refused(self):
        with self.assertRaisesRegex(ValueError, "actions/workflows/release.yml/runs"):
            self.run_plan(TAG, {RUNS_PATH: {"workflow_runs": {"id": 1}}})

    def test_an_artifact_without_a_boolean_expired_is_refused(self):
        for bad in ({"artifacts": [{"expired": "false"}]}, {"artifacts": [{}]}, {"artifacts": "none"}):
            with self.subTest(body=bad), self.assertRaisesRegex(ValueError, "artifacts"):
                self.run_plan(TAG, {RUNS_PATH: RUNS, ARTIFACTS_PATH: bad})

    def test_a_file_without_a_string_filename_is_refused(self):
        for bad in ({"files": [{"filename": 3}]}, {"files": [{}]}, {"files": None}):
            with self.subTest(body=bad), self.assertRaisesRegex(ValueError, "compare"):
                self.run_plan(event(), {COMPARE_PATH: bad})


if __name__ == "__main__":
    unittest.main()

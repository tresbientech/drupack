"""Proves the site cases run with no network: launches them inside a slim Python container."""

import os
import subprocess
import unittest
from pathlib import Path

import harness

# python:3.13-slim, the multi-platform index digest so the arm64 runner resolves it too:
# docker buildx imagetools inspect python:3.13-slim --format '{{.Manifest.Digest}}'
OFFLINE_IMAGE = "python:3.13-slim@sha256:8d9d0b8bcf6506481eae4907c18f5e3e7902e629f5f6d684f9e7c32e85e3ddf0"

TESTS_DIR = Path(__file__).resolve().parent.parent


class OfflineRun(harness.ConformanceCase):
    """Marked linux and docker: -k offline selects it; a missing docker fails it by name."""

    PLATFORMS = (harness.LINUX,)
    TOOLS = ("docker",)

    @classmethod
    def setUpClass(cls):
        # Checked before the tool mark below: the container has no docker, and that is
        # expected, not a missing tool to report.
        if harness.running_offline():
            raise unittest.SkipTest("already running inside the offline container")
        super().setUpClass()
        cls.case_dir = harness.RESULTS / cls.__name__
        cls.case_dir.mkdir(parents=True, exist_ok=True)

    def test_offline_site_cases(self):
        log_path = self.case_dir / "run.log"
        container_name = f"drupack-offline-{os.getpid()}"
        command = [
            "docker", "run", "--rm", "--network", "none",
            "--name", container_name,
            "--user", f"{os.getuid()}:{os.getgid()}",
            "-e", f"{harness.OFFLINE_ENV}=1",
            "-e", "DRUPACK_CACHE_DIR=/cache",
            "--mount", f"type=bind,src={harness.BINARY},dst=/artifact/drupack,readonly",
            "--mount", f"type=bind,src={TESTS_DIR},dst=/tests,readonly",
            "--mount", f"type=bind,src={self.case_dir},dst=/results",
            "--mount", f"type=bind,src={os.environ['DRUPACK_CACHE_DIR']},dst=/cache",
            "--entrypoint", "/usr/local/bin/python3",
            OFFLINE_IMAGE,
            "/tests/conformance", "/artifact/drupack", "/results",
        ]
        with open(log_path, "w") as log_handle:
            try:
                result = subprocess.run(
                    command, stdout=log_handle, stderr=subprocess.STDOUT,
                    timeout=harness.WAITS["offline"].seconds,
                )
            except subprocess.TimeoutExpired:
                # SIGKILL above lands on the docker client, not the daemon: the container
                # keeps running past the budget, holding these mounts, unless removed by
                # the name it was given.
                try:
                    subprocess.run(
                        ["docker", "rm", "-f", container_name],
                        capture_output=True, timeout=harness.WAITS["offline_kill"].seconds,
                    )
                except subprocess.TimeoutExpired:
                    pass
                self.fail(
                    f"offline container exceeded its {harness.WAITS['offline'].seconds}s "
                    f"budget and was killed: inspect {log_path}"
                )
        self.assertEqual(result.returncode, 0, f"inspect {log_path}")

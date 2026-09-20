"""Machinery shared by every conformance case: run cache, ports, readiness, stop, waits.

Importable with no built executable and no results directory: BINARY and RESULTS stay
None until tests/conformance/__main__.py sets them, before unittest imports a case module.
"""

import hashlib
import os
import platform
import shutil
import signal
import socket
import subprocess
import tempfile
import time
import unittest
from collections import namedtuple
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

# Set by __main__ before unittest discovers case modules. None when a module imports the
# harness on its own, as test_harness.py does.
BINARY = None
RESULTS = None

LINUX = "linux"
MACOS = "macos"
WINDOWS = "windows"

_PLATFORM_NAMES = {"Linux": LINUX, "Darwin": MACOS, "Windows": WINDOWS}


def current_platform():
    return _PLATFORM_NAMES[platform.system()]


# The offline case's docker invocation sets this in the container; running_offline() is
# true only for that inner run, never for the host run that launches the container.
OFFLINE_ENV = "CONFORMANCE_OFFLINE"


def running_offline():
    return os.environ.get(OFFLINE_ENV) == "1"


# Wait table: each row names the product deadline it covers, the harness wait above it, and
# the margin that buys. A row with no product deadline states its own budget instead.
Wait = namedtuple("Wait", ["name", "seconds", "deadline", "covers"])

WAIT_TABLE = [
    # packaging/entrypoint.go polls 2 minutes with a 30s per-request timeout: a request
    # already in flight when the poll gives up can still take 30s more, so the deadline
    # this row covers is 150s.
    Wait("start", 180, 150, "readiness of a start"),
    # entrypoint.go forces its own exit 10s after the first stop signal.
    Wait("stop", 30, 10, "stop after the first signal"),
    Wait("dr", 120, None, "a dr command"),
    Wait("php_cli", 30, None, "a php-cli probe"),
    # No product deadline: a start that should refuse an argument is expected to fail
    # before it ever reaches the point of attempting a connection.
    Wait("refusal", 20, None, "a start expected to refuse"),
    # No product deadline: budget for a base-image pull plus the inner run's own site
    # cases, which take under 2 minutes uncontained.
    Wait("offline", 600, None, "the offline container's full site-case run"),
    # No product deadline: docker rm -f on a name it just started, normally near-instant.
    Wait("offline_kill", 30, None, "removing a hung offline container after its budget expires"),
    # No product deadline: a cold start's own unpack, run by --help with no server to poll.
    Wait("unpack", 90, None, "an executable unpacking its runtime before --help exits"),
    # No product deadline: compiling a stdlib-only stub, normally a few seconds.
    Wait("fixture_build", 30, None, "compiling a fixture stub with go build"),
    # No product deadline: packing a launcher around the stub, which is itself a go build
    # of packaging/launcher and can need a first, uncached fetch of its module.
    Wait("fixture_pack", 180, None, "packing a fixture launcher with go run ./cmd/pack"),
    # No product deadline: a start denied room to unpack, behind a pull of the pinned image.
    Wait("cache_full", 120, None, "a start with no room in its cache root, including the image pull"),
    Wait("cache_full_kill", 30, None, "removing a hung cache-full container after its budget expires"),
    Wait("probe", 10, None, "a local process-table lookup (ps, pgrep) against a running start"),
    Wait("port_closed", 2, None, "confirming a stopped server's port refuses a connection"),
]

WAITS = {wait.name: wait for wait in WAIT_TABLE}


def pick_port():
    """Ask the OS for a free port on 127.0.0.1, then release it for the caller to bind."""
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
        probe.bind(("127.0.0.1", 0))
        return probe.getsockname()[1]


def fresh_dir(path):
    """Recreate path as an empty directory, for a case that needs its own cache, home or root."""
    shutil.rmtree(path, ignore_errors=True)
    path.mkdir(parents=True)
    return path


def reserved_dir(path):
    """Clear path without creating it, for a case that hands path to the launcher as a cache
    root. runtime.Root() creates a missing root itself, at the private mode 0700 privateRoot()
    requires; a directory this process pre-created would carry the host umask instead.
    """
    shutil.rmtree(path, ignore_errors=True)
    return path


# packaging/launcher, the module cmd/pack builds every fixture launcher from.
LAUNCHER_SRC = Path(__file__).resolve().parent.parent.parent / "packaging" / "launcher"

# The stub every fixture launcher packs as its runtime: it reports its own version, its
# arguments, one forwarded environment value and PHPRC, so a case can tell a fixture start
# from a real one and, on Windows in phase 10, prove the launcher forwards all four. The
# version is baked in as literal source text, once per build, like tests/windows/launcher.Tests.ps1's
# stub does for its own fixtures.
_STUB_SOURCE = """package main

import (
	"fmt"
	"os"
)

func main() {{
	fmt.Printf("fixture {version} args=%q env=%s phprc=%s", os.Args[1:], os.Getenv("DRUPACK_TEST_VALUE"), os.Getenv("PHPRC"))
}}
"""


def _build_stub(work, runtime_dir, entry, version):
    """Compile the stub into runtime_dir/entry. The source stays in work, beside runtime_dir
    rather than inside it, since cmd/pack collects every regular file the runtime holds.
    """
    source = work / "main.go"
    source.write_text(_STUB_SOURCE.format(version=version))
    subprocess.run(
        ["go", "build", "-o", str(runtime_dir / entry), str(source)],
        check=True, capture_output=True, text=True, timeout=WAITS["fixture_build"].seconds,
    )


def _run_pack(runtime_dir, entry, version, output):
    output.parent.mkdir(parents=True, exist_ok=True)
    subprocess.run(
        ["go", "run", "./cmd/pack", "-runtime", str(runtime_dir), "-entry", entry,
         "-version", version, "-source", ".", "-output", str(output)],
        cwd=LAUNCHER_SRC, check=True, capture_output=True, text=True,
        timeout=WAITS["fixture_pack"].seconds,
    )


def pack_fixture(entry, version, output):
    """Build a launcher whose runtime is the stub, packed under entry (the platform's entry name)."""
    with tempfile.TemporaryDirectory(prefix="drupack-fixture-") as work:
        work = Path(work)
        runtime_dir = work / "runtime"
        runtime_dir.mkdir()
        _build_stub(work, runtime_dir, entry, version)
        _run_pack(runtime_dir, entry, version, output)
    return output


def pack_corrupted_fixture(entry, version, output):
    """Build a fixture like pack_fixture, then flip one hex digit of its entry's checksum.

    The checksum is embedded in the built binary as literal hex text, so the edit is unique
    and leaves every other offset unchanged, unlike patching the compressed payload.
    """
    with tempfile.TemporaryDirectory(prefix="drupack-fixture-") as work:
        work = Path(work)
        runtime_dir = work / "runtime"
        runtime_dir.mkdir()
        _build_stub(work, runtime_dir, entry, version)
        correct = hashlib.sha256((runtime_dir / entry).read_bytes()).hexdigest()
        _run_pack(runtime_dir, entry, version, output)

    data = bytearray(output.read_bytes())
    needle = correct.encode()
    offset = data.find(needle)
    if offset < 0:
        raise AssertionError("expected checksum not found in the fixture binary")
    data[offset] = ord("0") if correct[0] != "0" else ord("1")
    output.write_bytes(data)
    return output


# POSIX only: sends to the process group. Windows support arrives in phase 9, and this is
# the one place it needs to branch.
def stop_process(process, timeout, context=""):
    """Send SIGTERM to the process group; kill and report a process that outlives timeout."""
    if process.poll() is not None:
        return
    os.killpg(process.pid, signal.SIGTERM)
    try:
        process.wait(timeout=timeout)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.wait()
        raise AssertionError(f"process {process.pid} ignored SIGTERM and was killed{context}")


# Reads one line of a running process' output, from the offset the case started at.
def wait_for_line(log_path, offset, prefix, timeout):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        for line in log_path.read_text(errors="replace")[offset:].splitlines():
            if line.startswith(prefix):
                return line[len(prefix):].strip()
        time.sleep(0.25)
    raise AssertionError(f"no line starting with {prefix!r} in {log_path}")


def run_dr(binary, work_dir, data_dir, *command):
    return subprocess.run(
        [str(binary), "dr", "--data-dir", str(data_dir), *command],
        cwd=work_dir, capture_output=True, text=True, timeout=WAITS["dr"].seconds,
    )


class Site:
    """One product process for one case: its own port, its own log, its own stop.

    The host name in every request is "localhost", never the loopback literal: settings.php
    trusts only '^localhost$', so a request addressed to 127.0.0.1 would be refused.
    """

    DEFAULT_PORT = 7225

    def __init__(self, binary, case_dir):
        self.binary = binary
        self.case_dir = case_dir
        self.log_path = case_dir / "run.log"
        self.process = None
        self.port = None

    def start(self, data_dir, *options, listen=True):
        self.port = pick_port() if listen else self.DEFAULT_PORT
        args = [str(self.binary), "--data-dir", str(data_dir)]
        if listen:
            args += ["--listen", f"127.0.0.1:{self.port}"]
        args += list(options)
        with open(self.log_path, "wb") as log_handle:
            self.process = subprocess.Popen(
                args, cwd=self.case_dir, stdout=log_handle, stderr=subprocess.STDOUT,
                start_new_session=True,
            )
        try:
            self._wait_ready()
        except AssertionError:
            self.stop()
            raise

    # The port accepts before the site can answer, and the runtime's own first request is
    # still running then. An HTTP 200 on /user/login is the site's own evidence that the
    # first request finished, unlike a bare port-accept, which would let a stop race it.
    def _wait_ready(self):
        timeout = WAITS["start"].seconds
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if self.process.poll() is not None:
                raise AssertionError(
                    f"port {self.port} runtime exited before answering: inspect {self.log_path}"
                )
            try:
                status, _, _ = self.fetch("/user/login")
                if status == 200:
                    return
            except (URLError, HTTPError, ConnectionError, TimeoutError):
                # A cold first request can outlast one 30s poll, same as the product's own
                # readiness client; that is a reason to retry, not to give up.
                pass
            time.sleep(0.25)
        raise AssertionError(
            f"port {self.port} did not answer /user/login within {timeout}s: "
            f"inspect {self.log_path}"
        )

    def fetch(self, path):
        request = Request(f"http://localhost:{self.port}{path}")
        with urlopen(request, timeout=30) as response:
            return response.status, response.headers, response.read().decode(errors="replace")

    def http(self, path):
        return self.fetch(path)[2]

    # A server that never reports ready still holds its port, so stopping it here keeps a
    # retry or a later case from finding that port already taken.
    def stop(self):
        if self.process is None:
            return
        stop_process(self.process, WAITS["stop"].seconds, context=f": inspect {self.log_path}")


class ConformanceCase(unittest.TestCase):
    """Base for every case module: gates the class on its declared platforms and tools."""

    PLATFORMS = ()
    TOOLS = ()

    @classmethod
    def setUpClass(cls):
        current = current_platform()
        if current not in cls.PLATFORMS:
            raise unittest.SkipTest(f"not marked for {current}")
        for tool in cls.TOOLS:
            if shutil.which(tool) is None:
                raise RuntimeError(f"{cls.__name__} needs {tool!r}, which is not on PATH")

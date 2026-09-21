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
import sys
import tempfile
import threading
import time
import unittest
from collections import namedtuple
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

try:
    import pty
except ImportError:  # Windows has no pty module; only a Linux or macOS case attaches one.
    pty = None

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
    # No product deadline: openBrowser runs in the same goroutine that already printed
    # "Drupack is ready.", so the recorder standing in for it sees the URL within a
    # process spawn, not a poll of any kind.
    Wait("browser_open", 10, None, "a start's background browser-open handing its target to the recorder"),
    Wait("dr", 120, None, "a dr command"),
    Wait("php_cli", 30, None, "a php-cli probe"),
    # No product deadline: a start that should refuse an argument is expected to fail
    # before it ever reaches the point of attempting a connection.
    Wait("refusal", 20, None, "a start expected to refuse"),
    # No product deadline: a start that should refuse only after it has bootstrapped
    # Drupal (or attempted to) against a real database, slower than an argument refusal.
    Wait("bootstrap_refusal", 180, None, "a start that reaches Drupal before refusing"),
    # No product deadline: budget for a base-image pull plus the inner run's own site
    # cases, which take under 2 minutes uncontained.
    Wait("offline", 600, None, "the offline container's full site-case run"),
    # No product deadline: docker rm -f on a name it just started, normally near-instant.
    Wait("offline_kill", 30, None, "removing a hung offline container after its budget expires"),
    # packaging/launcher/internal/runtime/lock_windows.go's lockRoot() bounds a losing
    # process's wait for the winner's exclusive lock handle at 60s on Windows, before it
    # can even begin its own unpack; the deadline this row covers is that 60s lock wait.
    Wait("unpack", 150, 60, "a losing process's Windows lock wait, then unpacking its runtime"),
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
    # packaging/entrypoint.go's readiness poller uses an http.Client{Timeout: 30 * time.Second}
    # for each request; every case's own HTTP call against a running site or a login link
    # carries the same per-request deadline.
    Wait("http_request", 60, 30, "an HTTP request against a running site or a login link"),
    # No product deadline: a first start records its pending administrator step once the seed
    # and settings steps finish, normally within a few seconds.
    Wait("progress", 180, None, "a first start to record its pending administrator step"),
    # No product deadline: budget for a database container's own image pull plus its startup.
    Wait("database_container", 300, None, "starting a database container, including an image pull"),
    Wait("database_ready", 180, None, "a database container answering a readiness probe"),
    # No product deadline: a first start installing into a live MySQL or PostgreSQL server,
    # slower than a local sqlite start; budget carried over from tests/server-database.sh.
    Wait("database_start", 600, None, "a start serving /user/login against a MySQL or PostgreSQL server"),
    # No product deadline: budget for a network case's site container, including a debian
    # image pull, mirroring database_container's role for the server-database cases.
    Wait("network_container", 300, None, "starting a network case's site container, including a debian image pull"),
    # No product deadline: the client container's own worst-case sequence, a 60s front-page
    # fetch plus four 10s private-path probes, with margin for the container's own start.
    Wait("network_client", 150, None, "a client container's requests against a network case's site container"),
    # No product deadline: a metadata call against a container already running, or a quick
    # network command run against neither.
    Wait("docker_admin", 30, None, "a short docker command: container port, exec, rm, logs; network create, rm"),
    Wait("lock_ack", 10, None, "a helper process to confirm it holds startup.lock before a blocked start runs"),
    # No product deadline: safety valve bounding how long the lock-holding helper waits for
    # its release signal, past whatever the blocked start and the assertions on it take.
    Wait("lock_hold", 60, None, "a helper process holding startup.lock until told to release it"),
    # No product deadline: budget for Windows to release a just-exited process's file
    # handles under the run cache, normally near-instant.
    Wait("cache_cleanup", 10, None, "a run cache directory outliving the process that unpacked into it"),
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
# from a real one and prove the launcher forwards all four. The version is baked in as
# literal source text, once per build.
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


def _run_pack(work, runtime_dir, entry, version, output):
    output.parent.mkdir(parents=True, exist_ok=True)
    # A launcher carries the application beside its runtime. A fixture's stub serves
    # no site, so it carries an empty archive, under a checksum of its own version so
    # two fixtures never share a cache entry.
    application = work / "app.tar"
    application.write_bytes(b"")
    checksum = work / "app_checksum.txt"
    checksum.write_text(hashlib.sha256(version.encode()).hexdigest())
    subprocess.run(
        ["go", "run", "./cmd/pack", "-runtime", str(runtime_dir), "-entry", entry,
         "-version", version, "-source", ".", "-output", str(output),
         "-app", str(application), "-app-checksum", str(checksum)],
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
        _run_pack(work, runtime_dir, entry, version, output)
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
        _run_pack(work, runtime_dir, entry, version, output)

    data = bytearray(output.read_bytes())
    needle = correct.encode()
    offset = data.find(needle)
    if offset < 0:
        raise AssertionError("expected checksum not found in the fixture binary")
    data[offset] = ord("0") if correct[0] != "0" else ord("1")
    output.write_bytes(data)
    return output


# The one place stopping branches on platform: POSIX signals the process group, which covers
# a launcher's own children too. Windows has neither process groups nor a way to signal
# another process, so it force-kills the whole tree at once instead, the same one step
# tests/windows/site.Tests.ps1's Stop-Site took with taskkill /T /F.
def stop_process(process, timeout, context=""):
    """Stop process and everything under it; kill and report one that outlives timeout."""
    if process.poll() is not None:
        return
    if current_platform() == WINDOWS:
        try:
            subprocess.run(["taskkill", "/T", "/F", "/PID", str(process.pid)],
                            capture_output=True, timeout=timeout)
            process.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            raise AssertionError(f"process {process.pid} outlived taskkill /T /F{context}")
        return
    os.killpg(process.pid, signal.SIGTERM)
    try:
        process.wait(timeout=timeout)
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        process.wait()
        raise AssertionError(f"process {process.pid} ignored SIGTERM and was killed{context}")


# Another place stopping branches on platform: Windows releases a just-exited process's
# file handles a moment after it exits, so the run cache directory that process unpacked
# into can still be held when the run tries to remove it; POSIX holds no such handle.
def remove_cache_dir(cache_dir, timeout):
    """Remove cache_dir, retrying past a held handle; print one line and keep whatever exit
    code the run already earned if cache_dir outlives timeout, rather than fail the run.
    """
    deadline = time.monotonic() + timeout
    while True:
        try:
            shutil.rmtree(cache_dir)
            return
        except PermissionError:
            if time.monotonic() >= deadline:
                print(f"could not remove the run cache, still held: {cache_dir}", file=sys.stderr)
                return
            time.sleep(0.25)


# Reads one line of a running process' output, from the offset the case started at.
def wait_for_line(log_path, offset, prefix, timeout):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        for line in log_path.read_text(errors="replace")[offset:].splitlines():
            if line.startswith(prefix):
                return line[len(prefix):].strip()
        time.sleep(0.25)
    raise AssertionError(f"no line starting with {prefix!r} in {log_path}")


def opener_name():
    """The name packaging/entrypoint.go's openBrowser looks up on PATH for this platform,
    so a case's recorder answers to the same name the product would call.
    """
    return "open" if current_platform() == MACOS else "xdg-open"


def install_recorder(directory):
    """Write an executable posing as this platform's browser opener into directory, meant to
    sit first on PATH for a start under a pseudo-terminal. Returns the script's own path and
    the file it appends each URL it receives to, one per line.

    A rerun into the same results directory must not find a URL the last run recorded here,
    so any file left from that run is cleared before the script is written.
    """
    directory.mkdir(parents=True, exist_ok=True)
    script = directory / opener_name()
    recorded = directory / "recorded-urls"
    recorded.unlink(missing_ok=True)
    script.write_text(f'#!/bin/sh\necho "$1" >> "{recorded}"\n')
    script.chmod(0o700)
    return script, recorded


def wait_for_recorded_url(recorded, timeout):
    """Poll recorded, the file install_recorder's script appends to, until a start's
    background browser-open has written a line. Bounded, so a browser that never opens
    fails the case instead of hanging it.
    """
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if recorded.exists():
            lines = recorded.read_text(errors="replace").splitlines()
            if lines:
                return lines[0]
        time.sleep(0.1)
    raise AssertionError(f"the browser recorder wrote no URL to {recorded} within {timeout}s")


# Dropping the browser-eligibility gate in phase 2 means any spawn of the product
# executable that inherits an interactive terminal now qualifies to open one. popen() and
# run() are the one place every such spawn in this suite pins standard input closed, so a
# case run from a developer's own terminal cannot hand a start a terminal by accident. The
# one deliberate exception is Site's own pseudo-terminal branch, which wants exactly that.
def popen(args, **kwargs):
    """subprocess.Popen with standard input pinned closed unless the caller overrides it."""
    kwargs.setdefault("stdin", subprocess.DEVNULL)
    return subprocess.Popen(args, **kwargs)


def run(args, **kwargs):
    """subprocess.run's counterpart to popen(): standard input pinned closed unless the
    caller overrides it.
    """
    kwargs.setdefault("stdin", subprocess.DEVNULL)
    return subprocess.run(args, **kwargs)


def run_dr(binary, work_dir, data_dir, *command):
    return run(
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
        self._pty_thread = None

    def start(self, data_dir, *options, listen=True, ready_wait="start", attach_pty=False, env=None):
        self.port = pick_port() if listen else self.DEFAULT_PORT
        args = [str(self.binary), "--data-dir", str(data_dir)]
        if listen:
            args += ["--listen", f"127.0.0.1:{self.port}"]
        args += list(options)
        # A caller's own subdirectory names one Site process among several sharing a case,
        # like every other case directory in this suite: created on first use, not in advance.
        self.case_dir.mkdir(parents=True, exist_ok=True)
        if attach_pty:
            self._start_under_pty(args, env)
        else:
            # popen() pins standard input closed: a headless start must never inherit a
            # terminal this process happens to have, or the product would treat it as
            # interactive too.
            with open(self.log_path, "wb") as log_handle:
                self.process = popen(
                    args, cwd=self.case_dir, stdout=log_handle,
                    stderr=subprocess.STDOUT, start_new_session=True, env=env,
                )
        try:
            self._wait_ready(ready_wait)
        except AssertionError:
            self.stop()
            raise

    def _start_under_pty(self, args, env):
        # The product checks stream_isatty(STDIN); a pseudo-terminal on all three streams
        # is the harness's own way to make that check true with no real terminal of its own.
        master_fd, slave_fd = pty.openpty()
        try:
            self.process = subprocess.Popen(
                args, cwd=self.case_dir, stdin=slave_fd, stdout=slave_fd, stderr=slave_fd,
                start_new_session=True, env=env,
            )
        finally:
            # The child already holds its own copy from the fork; the parent's must close so
            # a read on master_fd sees the far end hang up once the child does, instead of
            # blocking behind a second open writer.
            os.close(slave_fd)
        self._pty_master = master_fd
        self._pty_thread = threading.Thread(target=self._drain_pty, daemon=True)
        self._pty_thread.start()

    def _drain_pty(self):
        """Copy the pseudo-terminal's output into log_path, the same file a headless start
        writes directly, so every case reads a start's output the same way regardless of
        which branch produced it.
        """
        with open(self.log_path, "wb") as log_handle:
            while True:
                try:
                    chunk = os.read(self._pty_master, 4096)
                except OSError:
                    # The far end hung up: every fd onto the slave closed when the child exited.
                    break
                if not chunk:
                    break
                log_handle.write(chunk)
                log_handle.flush()
        os.close(self._pty_master)

    # The port accepts before the site can answer, and the runtime's own first request is
    # still running then. An HTTP 200 on /user/login is the site's own evidence that the
    # first request finished, unlike a bare port-accept, which would let a stop race it.
    def _wait_ready(self, ready_wait):
        timeout = WAITS[ready_wait].seconds
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
        with urlopen(request, timeout=WAITS["http_request"].seconds) as response:
            return response.status, response.headers, response.read().decode(errors="replace")

    def http(self, path):
        return self.fetch(path)[2]

    # A server that never reports ready still holds its port, so stopping it here keeps a
    # retry or a later case from finding that port already taken.
    def stop(self):
        if self.process is None:
            return
        stop_process(self.process, WAITS["stop"].seconds, context=f": inspect {self.log_path}")
        if self._pty_thread is not None:
            self._pty_thread.join(WAITS["stop"].seconds)


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

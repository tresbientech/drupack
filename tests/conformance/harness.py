"""Machinery shared by every conformance case: run cache, ports, readiness, stop, waits.

Importable with no built executable and no results directory: BINARY and RESULTS stay
None until tests/conformance/__main__.py sets them, before unittest imports a case module.
"""

import hashlib
import json
import os
import platform
import shutil
import signal
import socket
import ssl
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
# harness on its own. SITE is the site.json beside BINARY.
BINARY = None
RESULTS = None
SITE = None

# Every case serves HTTP unless it names a folder of its own: an empty value hides the
# machine's mkcert and local root from every start, so a developer's trust never changes
# what a case sees. tls_cases.py sets its own.
os.environ["DRUPACK_TLS_DIR"] = ""
# "none" stands in for a resolver that never answers *.drupack.site, so the machine's DNS
# never changes a URL. A case that wants the Site host sets DRUPACK_DNS_ANSWER itself.
os.environ["DRUPACK_DNS_ANSWER"] = "none"

LINUX = "linux"
MACOS = "macos"
WINDOWS = "windows"

_PLATFORM_NAMES = {"Linux": LINUX, "Darwin": MACOS, "Windows": WINDOWS}


def current_platform():
    return _PLATFORM_NAMES[platform.system()]


# runtime/php-extensions.txt names what every builder compiles, under
# static-php-cli's names. Some never answer to get_loaded_extensions():
# password-argon2 is a build input for argon2 support inside the standard
# extension, and Windows PHP has no pcntl or posix at all.
ALLOWLIST = Path(__file__).resolve().parent.parent.parent / "runtime" / "php-extensions.txt"
_UNLOADABLE = {LINUX: {"password-argon2"}, MACOS: {"password-argon2"},
               WINDOWS: {"password-argon2", "pcntl", "posix"}}
_REPORTED_AS = {"opcache": "zend opcache"}


# PHP compiles these whatever a build selects, so no allowlist names them.
# Read off a PHP 8.5 static build; a release that changes the set corrects it.
# FrankenPHP's php-cli adds frankenphp-cli, from 1.13 on.
ALWAYS_COMPILED = frozenset({
    "core", "date", "frankenphp-cli", "hash", "json", "lexbor", "pcre", "random",
    "reflection", "spl", "standard", "uri",
})


def expected_extensions():
    """The allowlist and the site's additions, under the names this platform's PHP
    reports them by."""
    listed = [line.split("#", 1)[0].strip() for line in ALLOWLIST.read_text().splitlines()]
    names = set()
    for name in [*listed, *SITE["extensions"]]:
        if name and name not in _UNLOADABLE[current_platform()]:
            names.add(_REPORTED_AS.get(name, name))
    return frozenset(names)


# The offline case's docker invocation sets this in the container; running_offline() is
# true only for that inner run, never for the host run that launches the container.
OFFLINE_ENV = "CONFORMANCE_OFFLINE"


def running_offline():
    return os.environ.get(OFFLINE_ENV) == "1"


def load_site(binary):
    """Reads the site.json a build writes beside the executable it packs."""
    return json.loads((binary.parent / "site.json").read_text())


def ready_line():
    """The line a start prints once its site answers, naming the executable."""
    return f"{SITE['name']} is ready."


def skip_report(skipped):
    """One line per skipped case, so a run on a host without a tool names what it did not prove."""
    return [f"  {test}: {reason}" for test, reason in skipped]


DOCKER_SKIP = "needs a Docker daemon, which does not answer"
RECIPE_SKIP = "the site has no recipe"
WRITABLE_SKIP = "the site lists no writable directory"
ENGINE_SKIP = "DRUPACK_TEST_ENGINE names no engine executable"
SYSTEM_TRUST_SKIP = "writes the machine's trust store, which DRUPACK_TEST_SYSTEM_TRUST=1 allows"
_docker_answers = None


def docker_answers():
    """Reports whether a Docker daemon answers, probing once per run."""
    global _docker_answers
    if _docker_answers is None:
        try:
            _docker_answers = shutil.which("docker") is not None and subprocess.run(
                ["docker", "info"], capture_output=True, timeout=WAITS["docker_probe"].seconds,
            ).returncode == 0
        except subprocess.TimeoutExpired:
            _docker_answers = False
    return _docker_answers


# Wait table: the product deadline each row covers (None: the row states its own budget).
Wait = namedtuple("Wait", ["name", "seconds", "deadline", "covers"])

WAIT_TABLE = [
    # watch.go polls 2 min with a 30s request timeout; a request in flight at the end adds 30s: 150s.
    Wait("start", 180, 150, "readiness of a start"),
    # watch.go forces the exit 10s after the first stop signal.
    Wait("stop", 30, 10, "stop after the first signal"),
    # openBrowser runs in the goroutine that printed the ready line: a process spawn, no poll.
    Wait("browser_open", 10, None, "a start's background browser-open handing its target to the recorder"),
    Wait("drush", 120, None, "a drush command"),
    Wait("php_cli", 30, None, "a php-cli probe"),
    Wait("docker_probe", 10, None, "docker info answering, or reporting no daemon"),
    # An argument refusal fails before any connection attempt.
    Wait("refusal", 20, None, "a start expected to refuse"),
    # Refuses after bootstrapping Drupal against a real database: slower than an argument refusal.
    Wait("bootstrap_refusal", 180, None, "a start that reaches Drupal before refusing"),
    # Base-image pull plus the inner run's site cases (under 2 minutes uncontained).
    Wait("offline", 600, None, "the offline container's full site-case run"),
    Wait("offline_kill", 30, None, "removing a hung offline container after its budget expires"),
    # lock_windows.go lockRoot() bounds a losing process's wait at 60s on Windows, before its own unpack.
    Wait("unpack", 150, 60, "a losing process's Windows lock wait, then unpacking its runtime"),
    Wait("fixture_build", 30, None, "compiling a fixture stub with go build"),
    # A go build of the launcher; the first run can fetch its module uncached.
    Wait("fixture_pack", 180, None, "packing a fixture launcher with go run ./cmd/pack"),
    Wait("cache_full", 120, None, "a start with no room in its cache root, including the image pull"),
    Wait("cache_full_kill", 30, None, "removing a hung cache-full container after its budget expires"),
    Wait("probe", 10, None, "a local process-table lookup (ps, pgrep) against a running start"),
    Wait("port_closed", 2, None, "confirming a stopped server's port refuses a connection"),
    # watch.go's readiness poller gives each request 30s; case requests carry the same deadline.
    Wait("http_request", 60, 30, "an HTTP request against a running site or a login link"),
    Wait("progress", 180, None, "a first start to record its pending administrator step"),
    Wait("database_container", 300, None, "starting a database container, including an image pull"),
    Wait("database_ready", 180, None, "a database container answering a readiness probe"),
    # Install into a live MySQL or PostgreSQL server, slower than sqlite; carried over from tests/server-database.sh.
    Wait("database_start", 600, None, "a start serving /user/login against a MySQL or PostgreSQL server"),
    Wait("database_install", 600, None, "Drush installing a site into a MySQL or PostgreSQL server"),
    Wait("network_container", 300, None, "starting a network case's site container, including a debian image pull"),
    # Worst case: a 60s front-page fetch plus four 10s private-path probes, plus the container start.
    Wait("network_client", 150, None, "a client container's requests against a network case's site container"),
    Wait("docker_admin", 30, None, "a short docker command: container port, exec, rm, logs; network create, rm"),
    Wait("lock_ack", 10, None, "a helper process to confirm it holds serving.lock before a blocked start runs"),
    # Safety valve past the blocked start and its assertions.
    Wait("lock_hold", 60, None, "a helper process holding serving.lock until told to release it"),
    # Windows releases a just-exited process's file handles under the run cache after a delay.
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


LAUNCHER_SRC = Path(__file__).resolve().parent.parent.parent / "launcher"

# The stub every fixture launcher packs as its runtime: it prints its version, its arguments,
# DRUPACK_TEST_VALUE and PHPRC, so a case can prove the launcher forwards all four.
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
    """Compile the stub into runtime_dir/entry. The source stays outside runtime_dir:
    cmd/pack collects every regular file the runtime holds."""
    source = work / "main.go"
    source.write_text(_STUB_SOURCE.format(version=version))
    subprocess.run(
        ["go", "build", "-o", str(runtime_dir / entry), str(source)],
        check=True, capture_output=True, text=True, timeout=WAITS["fixture_build"].seconds,
    )


def _run_pack(work, runtime_dir, entry, version, output, name):
    output.parent.mkdir(parents=True, exist_ok=True)
    # An empty archive, under a checksum of the version so two fixtures never share a cache entry.
    application = work / "app.tar"
    application.write_bytes(b"")
    checksum = work / "app_checksum.txt"
    checksum.write_text(hashlib.sha256(version.encode()).hexdigest())
    site = work / "site.json"
    site.write_text(json.dumps({"name": name}))
    subprocess.run(
        ["go", "run", "./cmd/pack", "-runtime", str(runtime_dir), "-entry", entry,
         "-version", version, "-source", ".", "-output", str(output),
         "-app", str(application), "-app-checksum", str(checksum),
         "-site", str(site), "-site-version", version],
        cwd=LAUNCHER_SRC, check=True, capture_output=True, text=True,
        timeout=WAITS["fixture_pack"].seconds,
    )


def pack_fixture(entry, version, output, name="fixture"):
    """Build a launcher whose runtime is the stub, packed under entry, for a site called name,
    which names its cache root."""
    with tempfile.TemporaryDirectory(prefix="drupack-fixture-") as work:
        work = Path(work)
        runtime_dir = work / "runtime"
        runtime_dir.mkdir()
        _build_stub(work, runtime_dir, entry, version)
        _run_pack(work, runtime_dir, entry, version, output, name)
    return output


def pack_corrupted_fixture(entry, version, output):
    """Build a fixture like pack_fixture, then flip one hex digit of its entry's checksum.

    The checksum is embedded in the binary as literal hex text, so the edit is unique and
    leaves every other offset unchanged.
    """
    with tempfile.TemporaryDirectory(prefix="drupack-fixture-") as work:
        work = Path(work)
        runtime_dir = work / "runtime"
        runtime_dir.mkdir()
        _build_stub(work, runtime_dir, entry, version)
        correct = hashlib.sha256((runtime_dir / entry).read_bytes()).hexdigest()
        _run_pack(work, runtime_dir, entry, version, output, "fixture")

    data = bytearray(output.read_bytes())
    needle = correct.encode()
    offset = data.find(needle)
    if offset < 0:
        raise AssertionError("expected checksum not found in the fixture binary")
    data[offset] = ord("0") if correct[0] != "0" else ord("1")
    output.write_bytes(data)
    return output


# POSIX signals the process group, which covers a launcher's children. Windows cannot signal
# another process, so it force-kills the tree with taskkill /T /F.
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


def kill_group(process, timeout):
    """SIGKILL process's group and wait until every member has exited. The leader's exit
    comes first, while a child can still hold the Site data's lock for a moment. A member
    waiting to be reaped (state Z) holds no lock, and no reaper runs in a container."""
    os.killpg(process.pid, signal.SIGKILL)
    process.wait(timeout=timeout)
    deadline = time.monotonic() + timeout
    while True:
        listing = subprocess.run(["ps", "-A", "-o", "pgid=,stat="], capture_output=True, text=True,
                                 check=True).stdout
        members = [line for line in listing.splitlines()
                   if line.split()[0] == str(process.pid) and not line.split()[1].startswith("Z")]
        if not members:
            return
        if time.monotonic() > deadline:
            raise AssertionError(f"process group {process.pid} outlived SIGKILL by {timeout}s")
        time.sleep(0.1)


# Windows holds a just-exited process's file handles a moment, so its run cache directory can
# still be locked here; POSIX does not.
def remove_cache_dir(cache_dir, timeout):
    """Remove cache_dir, retrying past a held handle; past timeout, print one line and keep
    the run's exit code."""
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


def wait_for_line(log_path, offset, prefix, timeout):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        for line in log_path.read_text(errors="replace")[offset:].splitlines():
            if line.startswith(prefix):
                return line[len(prefix):].strip()
        time.sleep(0.25)
    raise AssertionError(f"no line starting with {prefix!r} in {log_path}")


def opener_name():
    """The name runtime/entrypoint.go's openBrowser looks up on PATH for this platform."""
    return "open" if current_platform() == MACOS else "xdg-open"


def install_recorder(directory):
    """Write an executable posing as this platform's browser opener into directory, to sit
    first on PATH for a start under a pseudo-terminal. Returns the script's path and the file
    it appends each URL to, one per line. A file left by an earlier run is cleared first.
    """
    directory.mkdir(parents=True, exist_ok=True)
    script = directory / opener_name()
    recorded = directory / "recorded-urls"
    recorded.unlink(missing_ok=True)
    script.write_text(f'#!/bin/sh\necho "$1" >> "{recorded}"\n')
    script.chmod(0o700)
    return script, recorded


def wait_for_recorded_url(recorded, timeout):
    """Poll recorded until a start's browser-open has written a line; a browser that never
    opens fails the case."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if recorded.exists():
            lines = recorded.read_text(errors="replace").splitlines()
            if lines:
                return lines[0]
        time.sleep(0.1)
    raise AssertionError(f"the browser recorder wrote no URL to {recorded} within {timeout}s")


# Any spawn of the product that inherits an interactive terminal qualifies to open a browser.
# popen() and run() pin standard input closed, so a case run from a developer's terminal
# cannot hand a start one by accident. Site's pseudo-terminal branch is the exception.
def popen(args, **kwargs):
    """subprocess.Popen with standard input pinned closed unless the caller overrides it."""
    kwargs.setdefault("stdin", subprocess.DEVNULL)
    return subprocess.Popen(args, **kwargs)


def run(args, **kwargs):
    """subprocess.run's counterpart to popen()."""
    timeout = kwargs.get("timeout")
    if current_platform() != WINDOWS or timeout is None:
        kwargs.setdefault("stdin", subprocess.DEVNULL)
        return subprocess.run(args, **kwargs)
    # On a timeout subprocess.run kills the child alone, then reads its pipes to the end.
    # On Windows a grandchild that inherited them, such as trust's root-store prompt,
    # holds them open and the read never returns, so the whole tree goes first.
    del kwargs["timeout"]
    check = kwargs.pop("check", False)
    data = kwargs.pop("input", None)
    if data is None:
        kwargs.setdefault("stdin", subprocess.DEVNULL)
    else:
        kwargs["stdin"] = subprocess.PIPE
    if kwargs.pop("capture_output", False):
        kwargs["stdout"] = kwargs["stderr"] = subprocess.PIPE
    with subprocess.Popen(args, **kwargs) as process:
        try:
            stdout, stderr = process.communicate(data, timeout=timeout)
        except subprocess.TimeoutExpired:
            stop_process(process, timeout)
            raise
    result = subprocess.CompletedProcess(process.args, process.returncode, stdout, stderr)
    if check:
        result.check_returncode()
    return result


def run_drush(binary, work_dir, data_dir, *command):
    return run(
        [str(binary), "drush", "--data-dir", str(data_dir), *command],
        cwd=work_dir, capture_output=True, text=True, timeout=WAITS["drush"].seconds,
    )


class Site:
    """One product process for one case: its own port, its own log, its own stop.

    Requests use the host "localhost", never 127.0.0.1: settings.php trusts only '^localhost$'.

    A site given root serves HTTPS, and every request verifies its certificate against root.
    """

    def __init__(self, binary, case_dir, root=None):
        self.binary = binary
        self.case_dir = case_dir
        self.scheme = "http" if root is None else "https"
        self._context = None if root is None else ssl.create_default_context(cafile=str(root))
        self.log_path = case_dir / "run.log"
        self.process = None
        self.port = None
        self._pty_thread = None

    def start(self, data_dir, *options, listen=True, bind="127.0.0.1", ready_wait="start", attach_pty=False, env=None):
        self.port = pick_port() if listen else SITE["port"]
        # --foreground: a detached start would return at once.
        args = [str(self.binary), "--foreground", "--data-dir", str(data_dir)]
        if listen:
            args += ["--listen", f"{bind}:{self.port}"]
        args += list(options)
        # A case may start several Sites, each in its own subdirectory created on first use.
        self.case_dir.mkdir(parents=True, exist_ok=True)
        if attach_pty:
            self._start_under_pty(args, env)
        else:
            # popen() closes stdin: a headless start must not inherit a terminal.
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
        # The product checks stream_isatty(STDIN).
        master_fd, slave_fd = pty.openpty()
        try:
            self.process = subprocess.Popen(
                args, cwd=self.case_dir, stdin=slave_fd, stdout=slave_fd, stderr=slave_fd,
                start_new_session=True, env=env,
            )
        finally:
            # The parent's copy must close, or a read on master_fd never sees the hang-up.
            os.close(slave_fd)
        self._pty_master = master_fd
        self._pty_thread = threading.Thread(target=self._drain_pty, daemon=True)
        self._pty_thread.start()

    def _drain_pty(self):
        """Copy the pseudo-terminal's output into log_path, as a headless start writes it."""
        with open(self.log_path, "wb") as log_handle:
            while True:
                try:
                    chunk = os.read(self._pty_master, 4096)
                except OSError:
                    # Every fd onto the slave closed when the child exited.
                    break
                if not chunk:
                    break
                log_handle.write(chunk)
                log_handle.flush()
        os.close(self._pty_master)

    # The port accepts before the runtime's first request finishes. A 200 on /user/login proves
    # it did, where a bare port-accept would let a stop race it.
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
                # A cold first request can outlast one 30s poll, as in the product's own client.
                pass
            time.sleep(0.25)
        raise AssertionError(
            f"port {self.port} did not answer /user/login within {timeout}s: "
            f"inspect {self.log_path}"
        )

    def fetch_bytes(self, path, headers=None):
        request = Request(f"{self.scheme}://localhost:{self.port}{path}", headers=headers or {})
        with urlopen(request, timeout=WAITS["http_request"].seconds, context=self._context) as response:
            return response.status, response.headers, response.read()

    def fetch(self, path, headers=None):
        status, response_headers, body = self.fetch_bytes(path, headers)
        return status, response_headers, body.decode(errors="replace")

    def http(self, path):
        return self.fetch(path)[2]

    # A server that never reports ready still holds its port.
    def stop(self):
        if self.process is None:
            return
        stop_process(self.process, WAITS["stop"].seconds, context=f": inspect {self.log_path}")
        if self._pty_thread is not None:
            self._pty_thread.join(WAITS["stop"].seconds)


def refuse(case, case_dir, name, *args):
    """Run a start that bootstraps Drupal against a real database before refusing; return
    its combined output, waiting on the bootstrap_refusal budget.

    A start checks who owns its address before refusing, so each gets its own port; the
    machine-global default would answer for whatever else holds it.
    """
    log = case_dir / f"{name}.log"
    with open(log, "wb") as handle:
        try:
            result = run(
                [str(BINARY), *args, "--foreground", "--listen", f"127.0.0.1:{pick_port()}"],
                cwd=case_dir, stdout=handle, stderr=subprocess.STDOUT,
                timeout=WAITS["bootstrap_refusal"].seconds,
            )
        except subprocess.TimeoutExpired:
            case.fail(f"{name}: the start kept running instead of refusing within "
                      f"{WAITS['bootstrap_refusal'].seconds}s: inspect {log}")
    case.assertNotEqual(result.returncode, 0, f"{name}: the start succeeded instead of refusing: inspect {log}")
    return log.read_text(errors="replace")


def engine_executable():
    """The engine executable DRUPACK_TEST_ENGINE names, or None when it names none."""
    path = os.environ.get("DRUPACK_TEST_ENGINE")
    return Path(path).resolve() if path else None


class ConformanceCase(unittest.TestCase):
    """Base for every case module. Gates: PLATFORMS, TOOLS, RECIPE (the site's recipe),
    WRITABLE (a writable directory), ENGINE (DRUPACK_TEST_ENGINE) and SYSTEM_TRUST
    (DRUPACK_TEST_SYSTEM_TRUST=1). Each class writes under class_dir, each test under case_dir.
    """

    PLATFORMS = ()
    TOOLS = ()
    RECIPE = True
    WRITABLE = False
    ENGINE = False
    SYSTEM_TRUST = False

    def __init_subclass__(cls, **kwargs):
        super().__init_subclass__(**kwargs)
        # A class marked for no known platform would skip on every one while the suite stays green.
        unknown = set(cls.PLATFORMS) - {LINUX, MACOS, WINDOWS}
        if not cls.PLATFORMS or unknown:
            raise TypeError(f"{cls.__name__} marks no known platform: {cls.PLATFORMS!r}")
        # A setUpClass of its own, or a mixin's, that forgets super() would skip the gates,
        # so they run before it whatever its body calls.
        own = cls.setUpClass.__func__
        if own is not ConformanceCase.setUpClass.__func__ and not getattr(own, "gates", False):
            def gated(klass):
                ConformanceCase.setUpClass.__func__(klass)
                own(klass)
            gated.gates = True
            cls.setUpClass = classmethod(gated)

    @classmethod
    def setUpClass(cls):
        # A gated setUpClass that also calls super() reaches this a second time.
        if "class_dir" in cls.__dict__:
            return
        current = current_platform()
        if current not in cls.PLATFORMS:
            raise unittest.SkipTest(f"not marked for {current}")
        if cls.RECIPE and not SITE["recipe"]:
            raise unittest.SkipTest(RECIPE_SKIP)
        if cls.WRITABLE and not SITE["writable"]:
            raise unittest.SkipTest(WRITABLE_SKIP)
        if cls.ENGINE and engine_executable() is None:
            raise unittest.SkipTest(ENGINE_SKIP)
        if cls.SYSTEM_TRUST and os.environ.get("DRUPACK_TEST_SYSTEM_TRUST") != "1":
            raise unittest.SkipTest(SYSTEM_TRUST_SKIP)
        for tool in cls.TOOLS:
            # A CI runner without a daemon still runs every case that needs none.
            if tool == "docker" and not docker_answers():
                raise unittest.SkipTest(DOCKER_SKIP)
            if shutil.which(tool) is None:
                raise RuntimeError(f"{cls.__name__} needs {tool!r}, which is not on PATH")
        cls.class_dir = RESULTS / cls.__name__
        cls.class_dir.mkdir(parents=True, exist_ok=True)

    def setUp(self):
        self.case_dir = self.class_dir / self._testMethodName
        self.case_dir.mkdir(parents=True, exist_ok=True)


# The account every server-database case connects as; a CI service named by the variables
# below creates it: database drupal, user drupal, this password.
DATABASE_USER = "drupal"
DATABASE_PASSWORD = "Server.database.test.2026"

# mysql:8.4.11 and postgres:17.11, pinned by index digest:
# docker buildx imagetools inspect IMAGE --format '{{.Manifest.Digest}}'
_DATABASE_CONTAINERS = {
    "mysql": (
        "mysql:8.4.11@sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a", 3306,
        ("-e", "MYSQL_DATABASE=drupal", "-e", f"MYSQL_USER={DATABASE_USER}",
         "-e", f"MYSQL_PASSWORD={DATABASE_PASSWORD}", "-e", "MYSQL_RANDOM_ROOT_PASSWORD=yes"),
    ),
    "pgsql": (
        "postgres:17.11@sha256:67f41722b7a8cbdb868a44a4995c846eddfdc2973bccb291ce937dce88ad5675", 5432,
        ("-e", "POSTGRES_DB=drupal", "-e", f"POSTGRES_USER={DATABASE_USER}",
         "-e", f"POSTGRES_PASSWORD={DATABASE_PASSWORD}"),
    ),
}

# host:port of a server the caller already runs, such as a CI service.
DATABASE_VARIABLES = {"mysql": "DRUPACK_TEST_MYSQL", "pgsql": "DRUPACK_TEST_PGSQL"}

# Runs one statement through PDO on the product's own PHP, so no database client is needed.
_DATABASE_CLIENT = r"""<?php
$pdo = new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = getenv('DB_SQL');
if ($sql !== '') {
    $statement = $pdo->query($sql);
    if (getenv('DB_FETCH') === '1') {
        echo $statement->fetchColumn();
    }
}
"""


def database_address(backend):
    """The (host, port) DATABASE_VARIABLES names for backend, or None when it is unset."""
    variable = DATABASE_VARIABLES[backend]
    value = os.environ.get(variable)
    if not value:
        return None
    # The variable comes from whoever runs the suite, so a malformed one stops the run.
    host, separator, port = value.rpartition(":")
    if not separator or not host or not port.isdigit():
        raise ValueError(f"{variable} must be HOST:PORT, got {value!r}")
    return host, int(port)


class DatabaseServer:
    """A MySQL or PostgreSQL server for the cases that need one: the one its variable names,
    or a container this run starts, which needs a Docker daemon.
    """

    def __init__(self, backend, label, log_dir):
        self.backend = backend
        self.log_dir = log_dir
        self.container = None
        self._label = label
        self.host, self.port = None, None

    def start(self):
        address = database_address(self.backend)
        if address is not None:
            self.host, self.port = address
        else:
            if not docker_answers():
                raise unittest.SkipTest(f"{DOCKER_SKIP}, and {DATABASE_VARIABLES[self.backend]} is unset")
            self._start_container()
        try:
            self._wait_ready()
        except Exception:
            self.stop()
            raise

    def _start_container(self):
        image, port, environment = _DATABASE_CONTAINERS[self.backend]
        self.container = f"drupack-{self._label}-{self.backend}-{os.getpid()}"
        subprocess.run(
            ["docker", "run", "-d", "--name", self.container, "-p", f"127.0.0.1::{port}", *environment, image],
            check=True, capture_output=True, timeout=WAITS["database_container"].seconds,
        )
        output = subprocess.run(
            ["docker", "port", self.container, f"{port}/tcp"], check=True,
            capture_output=True, text=True, timeout=WAITS["docker_admin"].seconds,
        ).stdout
        self.host = "127.0.0.1"
        self.port = int(output.strip().splitlines()[0].rsplit(":", 1)[-1])

    def _wait_ready(self):
        # MySQL's initialization server listens on a socket only, so a TCP connection
        # waits for the final server.
        deadline = time.monotonic() + WAITS["database_ready"].seconds
        while time.monotonic() < deadline:
            if self._client("drupal", "").returncode == 0:
                return
            time.sleep(2)
        raise AssertionError(
            f"the {self.backend} server at {self.host}:{self.port} did not answer within "
            f"{WAITS['database_ready'].seconds}s"
        )

    def _client(self, database, sql, fetch=False):
        script = RESULTS / "database-client.php"
        if not script.exists():
            script.write_text(_DATABASE_CLIENT)
        env = dict(os.environ, DB_DSN=f"{self.backend}:host={self.host};port={self.port};dbname={database}",
                   DB_USER=DATABASE_USER, DB_PASSWORD=DATABASE_PASSWORD, DB_SQL=sql,
                   DB_FETCH="1" if fetch else "0")
        return run([str(BINARY), "php-cli", str(script)], env=env, capture_output=True, text=True,
                   timeout=WAITS["php_cli"].seconds)

    def execute(self, database, sql):
        result = self._client(database, sql)
        if result.returncode != 0:
            raise AssertionError(f"{sql!r} failed on {self.backend}: {result.stdout}{result.stderr}")

    def query(self, database, sql):
        """The first column of the first row sql returns."""
        result = self._client(database, sql, fetch=True)
        if result.returncode != 0:
            raise AssertionError(f"{sql!r} failed on {self.backend}: {result.stdout}{result.stderr}")
        return result.stdout.strip()

    def connection(self, database="drupal"):
        """The launch options that point a first start at database on this server."""
        return ["--database", self.backend, "--db-host", self.host, "--db-port", str(self.port),
                "--db-name", database, "--db-user", DATABASE_USER, "--db-password", DATABASE_PASSWORD]

    def stop(self):
        if self.container is None:
            return
        with open(self.log_dir / f"{self.backend}-server.log", "wb") as handle:
            subprocess.run(["docker", "logs", self.container], stdout=handle, stderr=subprocess.STDOUT,
                            timeout=WAITS["docker_admin"].seconds)
        # The database images declare a VOLUME; -v removes the anonymous one this run created.
        subprocess.run(["docker", "rm", "-f", "-v", self.container], capture_output=True,
                        timeout=WAITS["docker_admin"].seconds)
        self.container = None

"""Bundled Node: the node, npm and npx commands, Node first on PATH for Drush and the
server, the release unpacked once into the cache, clean, and the refusals of a musl
build and of a site that carries no Node.
"""

import json
import os
import unittest
from pathlib import Path

import harness

NODE_SKIP = "the site carries no Node"
MUSL_SKIP = "a musl build carries no Node"
GLIBC_SKIP = "only a musl build of a site carrying Node refuses these commands"


def musl():
    """A Linux build names its musl file with a -musl suffix."""
    return harness.BINARY.stem.endswith("-musl")


def run(case_dir, *args, env=None, cwd=None):
    return harness.run([str(harness.BINARY), *args], cwd=cwd or case_dir, env=env,
                       capture_output=True, text=True, timeout=harness.WAITS["unpack"].seconds)


def release(cache):
    """The one unpacked Node release under cache."""
    releases = [path for path in (cache / "node").iterdir() if path.is_dir()]
    if len(releases) != 1:
        raise AssertionError(f"{cache / 'node'} holds {releases}, not one release")
    return releases[0]


def executables(directory):
    """Node's executable directory inside an unpacked release: the zip keeps node.exe at its root."""
    return directory if harness.current_platform() == harness.WINDOWS else directory / "bin"


def npm_version(directory):
    modules = "node_modules" if harness.current_platform() == harness.WINDOWS else "lib/node_modules"
    return json.loads((directory / modules / "npm" / "package.json").read_text())["version"]


class NodeCase(harness.ConformanceCase):
    """A class for a site that carries Node, on a file that carries it, with a cache of its own."""

    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)
    RECIPE = False

    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        if not harness.SITE.get("node"):
            raise unittest.SkipTest(NODE_SKIP)
        if musl():
            raise unittest.SkipTest(MUSL_SKIP)
        cls.cache = harness.reserved_dir(cls.class_dir / "cache")
        cls.env = dict(os.environ, DRUPACK_CACHE_DIR=str(cls.cache))


class NodeCommands(NodeCase):

    def test_node_npm_and_npx_run_the_bundled_release(self):
        result = run(self.case_dir, "node", "--version", env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "v" + harness.SITE["node"])
        bundled = npm_version(release(self.cache))
        for program in ("npm", "npx"):
            with self.subTest(program=program):
                result = run(self.case_dir, program, "--version", env=self.env)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout.strip(), bundled)

    def test_node_runs_in_the_readers_directory_with_the_readers_arguments(self):
        reader = self.case_dir / "reader dir"
        reader.mkdir(exist_ok=True)
        result = run(self.case_dir, "node", "-e", "console.log(process.cwd()); console.log(process.argv[1])",
                     "an argument", env=self.env, cwd=reader)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.splitlines(), [str(reader.resolve()), "an argument"])

    def test_a_second_start_leaves_the_unpacked_release_alone(self):
        result = run(self.case_dir, "node", "--version", env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        directory = release(self.cache)
        before = directory.stat().st_mtime_ns
        result = run(self.case_dir, "node", "--version", env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("Unpacking Node", result.stderr)
        self.assertEqual(release(self.cache), directory)
        self.assertEqual(directory.stat().st_mtime_ns, before, "a second start changed the unpacked release")

    def test_the_unpacked_release_holds_the_node_license(self):
        result = run(self.case_dir, "node", "--version", env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue((release(self.cache) / "LICENSE").is_file(), "the unpacked release has no LICENSE")


class NodeClean(NodeCase):

    def test_clean_lists_then_removes_the_unpacked_release(self):
        result = run(self.case_dir, "node", "--version", env=self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        directory = release(self.cache)

        listing = run(self.case_dir, "clean", "--dry-run", env=self.env)
        self.assertEqual(listing.returncode, 0, listing.stderr)
        self.assertIn(f"node/{directory.name}", listing.stdout)
        self.assertIn("1 unpacked Node release.", listing.stdout)
        self.assertTrue(directory.is_dir(), "a dry run removed the unpacked release")

        removal = run(self.case_dir, "clean", env=self.env)
        self.assertEqual(removal.returncode, 0, removal.stderr)
        self.assertIn("Removed 1 unpacked Node release", removal.stdout)
        self.assertFalse(directory.exists(), "clean left the unpacked release")


class NodeOnPath(NodeCase):
    """The launcher execs the runtime, and launch.php execs the server, so the start's
    process is the server whose PHP answers each request, and its environment is the one
    that PHP and every program it runs inherit."""

    PLATFORMS = (harness.LINUX,)
    RECIPE = True

    def test_the_server_and_drush_find_the_bundled_node_first(self):
        data = self.case_dir / "data"
        site = harness.Site(harness.BINARY, self.case_dir)
        site.start(data, env=self.env)
        try:
            environment = Path(f"/proc/{site.process.pid}/environ").read_bytes().split(b"\0")
        finally:
            site.stop()
        path = next(entry for entry in environment if entry.startswith(b"PATH="))[5:].decode()
        bundled = executables(release(self.cache))
        self.assertEqual(Path(path.split(os.pathsep)[0]), bundled, f"the server's PATH is {path}")

        result = harness.run(
            [str(harness.BINARY), "drush", "--data-dir", str(data), "php:eval",
             "echo exec('command -v node'), PHP_EOL, exec('node --version'), PHP_EOL;"],
            cwd=self.case_dir, env=self.env, capture_output=True, text=True,
            timeout=harness.WAITS["drush"].seconds,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.splitlines(), [str(bundled / "node"), "v" + harness.SITE["node"]])


class NodeFetchesPackages(NodeCase):
    """npx fetches from the npm registry, with npm's cache in the reader's home."""

    PLATFORMS = (harness.LINUX, harness.MACOS)

    def test_npx_runs_a_package_it_fetches(self):
        if harness.running_offline():
            self.skipTest("no network inside the offline container")
        home = harness.fresh_dir(self.case_dir / "home")
        env = dict(self.env, HOME=str(home))
        env.pop("npm_config_cache", None)
        result = run(self.case_dir, "npx", "-y", "cowsay", "hi", env=env)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("< hi >", result.stdout)
        self.assertTrue((home / ".npm" / "_cacache").is_dir(), "npm kept no cache in the reader's home")


class NodeRefusals(harness.ConformanceCase):
    """A site without Node has no such command. A musl build of a site with Node names the
    glibc build, which carries it."""

    PLATFORMS = (harness.LINUX, harness.MACOS, harness.WINDOWS)
    RECIPE = False

    def test_a_site_without_node_answers_node_as_an_unknown_command(self):
        if harness.SITE.get("node"):
            self.skipTest("the site carries Node")
        result = run(self.case_dir, "node", "--version")
        self.assertEqual(result.returncode, 1, result.stdout)
        self.assertIn("Unknown command: node", result.stderr)

    def test_a_musl_build_names_the_glibc_build(self):
        if not harness.SITE.get("node") or not musl():
            self.skipTest(GLIBC_SKIP)
        for program in ("node", "npm", "npx"):
            with self.subTest(program=program):
                result = run(self.case_dir, program, "--version")
                self.assertEqual(result.returncode, 1, result.stdout)
                self.assertIn("glibc build", result.stderr)

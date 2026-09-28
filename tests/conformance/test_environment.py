"""The DRUPACK_RUNTIME_* variables a start passes between its processes, checked against
runtime/environment.txt. Each declared line names the files that write the variable and
the files that read it, and this test finds both in the sources by pattern. Run with:

    python3 -m unittest discover -s tests/conformance -p test_environment.py
"""

import re
import subprocess
import unittest
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DECLARATION = ROOT / "runtime" / "environment.txt"
# The product's own sources. Tests set variables to build states, so they stay out.
SOURCES = ("application", "build", "engine", "launcher", "runtime")
NAME = r"DRUPACK_RUNTIME_([A-Z_]+)"

# Per file type, the patterns that mark a line as writing or reading a variable.
PATTERNS = {
    ".go": (
        [rf'os\.Setenv\("{NAME}"', rf'"{NAME}=[^"]*"'],
        [rf'os\.Getenv\("{NAME}"\)'],
    ),
    ".php": (
        [rf"putenv\(.*?{NAME}"],
        [rf"getenv\(['\"]{NAME}['\"]\)", rf"environment\(['\"]{NAME}['\"]\)"],
    ),
    ".sh": (
        [rf"^\s*(?:export\s+)?{NAME}=", rf"^\s*export\s+{NAME}\s*$"],
        [rf"\$\{{?{NAME}"],
    ),
}


def sources():
    """Tracked product files, with the paths the repository spells, relative to its root."""
    listed = subprocess.run(["git", "ls-files", *SOURCES], cwd=ROOT, capture_output=True,
                            text=True, check=True).stdout.split()
    # A tracked symlink repeats the file it points at, which the listing names already.
    return [path for path in listed
            if "/tests/" not in path and not path.endswith("_test.go")
            and (ROOT / path).is_file() and not (ROOT / path).is_symlink()]


def found():
    """{name: {"writers": {file: line}, "readers": {file: line}}} as the sources hold them."""
    uses = defaultdict(lambda: {"writers": {}, "readers": {}})
    for path in sources():
        patterns = PATTERNS.get(Path(path).suffix)
        if patterns is None:
            continue
        writes, reads = patterns
        for number, line in enumerate((ROOT / path).read_text(errors="replace").splitlines(), 1):
            for role, expressions in (("writers", writes), ("readers", reads)):
                for expression in expressions:
                    for match in re.finditer(expression, line):
                        uses[match.group(1)][role].setdefault(path, number)
    return uses


def declared():
    """{name: {"writers": set, "readers": set}} from runtime/environment.txt."""
    declaration = {}
    for number, line in enumerate(DECLARATION.read_text().splitlines(), 1):
        if not line.strip() or line.startswith("#"):
            continue
        name, *fields = line.split()
        entry = dict(field.split("=", 1) for field in fields)
        for key in ("writers", "readers"):
            if key not in entry:
                raise AssertionError(f"{DECLARATION.name}:{number} declares {name} without {key}=")
        declaration[name] = {key: set(entry[key].split(",")) - {""} for key in ("writers", "readers")}
    return declaration


class EnvironmentContract(unittest.TestCase):
    def test_every_variable_the_sources_use_is_declared(self):
        declaration = declared()
        for name, roles in sorted(found().items()):
            with self.subTest(variable=name):
                if name not in declaration:
                    line = (f"{name}  writers={','.join(sorted(roles['writers']))}"
                            f"  readers={','.join(sorted(roles['readers']))}")
                    self.fail(f"DRUPACK_RUNTIME_{name} is used but {DECLARATION.name} lacks it. Add:\n  {line}")

    def test_each_declared_writer_and_reader_matches_the_sources(self):
        uses = found()
        for name, roles in sorted(declared().items()):
            for role in ("writers", "readers"):
                with self.subTest(variable=name, role=role):
                    actual = uses[name][role]
                    for path in sorted(roles[role] - actual.keys()):
                        self.fail(f"{path} is a declared {role[:-1]} of DRUPACK_RUNTIME_{name} but no longer "
                                  f"{'writes' if role == 'writers' else 'reads'} it. Restore it, or drop "
                                  f"{path} from {role}= in {DECLARATION.name}.")
                    for path in sorted(actual.keys() - roles[role]):
                        self.fail(f"{path}:{actual[path]} {'writes' if role == 'writers' else 'reads'} "
                                  f"DRUPACK_RUNTIME_{name}. Add {path} to its {role}= in {DECLARATION.name}.")

    def test_each_declared_variable_has_a_writer_and_a_reader(self):
        for name, roles in sorted(declared().items()):
            with self.subTest(variable=name):
                self.assertTrue(roles["writers"], f"{name} declares no writer")
                self.assertTrue(roles["readers"], f"{name} declares no reader")

    def test_no_caddyfile_reads_the_environment(self):
        # The entry points write each Caddyfile from its template, so a placeholder would
        # reach Caddy unset and resolve to an empty string.
        for path in sources():
            if Path(path).name == "Caddyfile" or path.endswith(".caddy"):
                with self.subTest(path=path):
                    self.assertNotIn("{$DRUPACK_RUNTIME_", (ROOT / path).read_text())


if __name__ == "__main__":
    unittest.main()

"""The tables in main the bot takes every word from, and the vocabulary they make (ADR-0019).

- `.github/gates.json`: per CI job, what it checks, how to reproduce and fix
  it, the evidence it leaves and its own rules;
- `.github/bot/rules/<tool>.json`: PHPStan identifiers, Rector rules, Pint
  fixers and markdownlint rules;
- `ARCHITECTURE.md`: the package's own rules;
- `.github/bot/explain.json`: every sentence the explainer, the relay, the
  checklist and the welcome write;
- `cliff.toml`: the changelog's groups;
- `.github/bot/config.json`: every limit and list the bot works by: the
  workflows it relays, the steps whose failure is the infrastructure's, the
  caps on evidence, comments and fixes, and who is welcomed.

Every function here reads text or files of main, never a pull request.
"""
import json
import re
from pathlib import Path

GATES = Path(".github/gates.json")
RULES = Path(".github/bot/rules")
ARCHITECTURE = Path("ARCHITECTURE.md")
EXPLAIN = Path(".github/bot/explain.json")
CONFIG = Path(".github/bot/config.json")
SPEC_MAP = Path(".github/spec-map.json")
DECISIONS = Path(".docs/decisions")
TROUBLESHOOTING = Path(".docs/guide/troubleshooting.md")
CLIFF = Path("cliff.toml")

# The tools a table of .github/bot/rules explains.
RULE_TABLES = ("phpstan", "pint", "rector", "markdownlint")

# The tools whose findings may name one of the package's own rules.
NAMING_RULES = ("phpstan", "pest")

# A rule row of ARCHITECTURE.md: `| **A1** | what it says | what enforces it |`.
ROW = re.compile(r"^\|\s*\*{0,2}([A-Z]\d{1,2})\*{0,2}\s*\|\s*(.+?)\s*\|\s*(.+?)\s*\|\s*$")

# A message slug's section of the troubleshooting guide.
SLUG_HEADING = re.compile(r"^## ([a-z0-9]+(?:-[a-z0-9]+)*)\s*$", re.MULTILINE)

# An expression of a job's name in a workflow, which a run fills in.
EXPRESSION = re.compile(r"\$\{\{.*?\}\}")

PLACEHOLDER = re.compile(r"\{([a-z_]+)\}")


def fill(template: str, values: dict[str, str]) -> str:
    """A sentence's text with every placeholder filled; a missing value is an error."""
    return PLACEHOLDER.sub(lambda match: values[match[1]], template)


def package_rules(text: str) -> dict[str, tuple[str, int]]:
    """Each rule of ARCHITECTURE.md: what it says, and the line it is on."""
    rules = {}
    for number, line in enumerate(text.splitlines(), start=1):
        row = ROW.match(line)
        if row:
            rules[row[1]] = (row[2], number)
    return rules


def slugs(text: str) -> set[str]:
    """Every message slug the troubleshooting guide has a section for."""
    return set(SLUG_HEADING.findall(text))


def job_pattern(check: str) -> re.Pattern:
    """A job's name as a run shows it, where the workflow writes it with expressions."""
    return re.compile("^" + ".+?".join(re.escape(part) for part in EXPRESSION.split(check)) + "$")


def gate_of(job: str, gates: dict) -> str:
    """The gate a job of a run is, by its name as main's gates.json writes it, or ''."""
    for gate, entry in gates.items():
        if job_pattern(entry["check"]).match(job):
            return gate
    return ""


def producers(entry: dict) -> list[dict]:
    """The evidence a gate's job leaves: a list, or none where it says why it leaves none."""
    evidence = entry.get("evidence")
    return evidence if isinstance(evidence, list) else []


class Tables:
    """main's tables, read once."""

    def __init__(self, root: Path = Path(".")):
        self.gates = json.loads((root / GATES).read_text(encoding="utf-8"))["gates"]
        self.rules = {
            tool: json.loads((root / RULES / f"{tool}.json").read_text(encoding="utf-8"))["rules"] for tool in RULE_TABLES
        }
        self.package = package_rules((root / ARCHITECTURE).read_text(encoding="utf-8"))
        self.words = json.loads((root / EXPLAIN).read_text(encoding="utf-8"))
        self.config = json.loads((root / CONFIG).read_text(encoding="utf-8"))
        self.spec_map = json.loads((root / SPEC_MAP).read_text(encoding="utf-8"))["globs"]
        self.decisions = {path.name[:4]: (DECISIONS / path.name).as_posix() for path in (root / DECISIONS).glob("[0-9][0-9][0-9][0-9]-*.md")}
        self.slugs = slugs((root / TROUBLESHOOTING).read_text(encoding="utf-8"))
        self.cliff = (root / CLIFF).read_text(encoding="utf-8")

    def say(self, key: str, **values: str) -> str:
        """A sentence of explain.json, filled."""
        return fill(self.words["says"][key], values)

    def tools_of(self, gate: str) -> list[str]:
        """The tools a gate's evidence may name."""
        return [producer["tool"] for producer in producers(self.gates.get(gate, {}))]

    def explanation(self, gate: str, tool: str, rule: str) -> tuple[str, str] | None:
        """What main says a rule a gate's tool reported means, and where to read more; None outside the vocabulary."""
        if tool not in self.tools_of(gate):
            return None
        table = self.rules.get(tool, {})
        named = str(table[rule].get("architecture", "")) if rule in table else rule if tool in NAMING_RULES else ""
        if named in self.package:
            says, line = self.package[named]
            return says, f"{ARCHITECTURE.as_posix()}#L{line}"
        if rule in table and "says" in table[rule]:
            return str(table[rule]["says"]), str(table[rule].get("link", ""))
        own = self.gates[gate].get("rules", {})
        if rule in own:
            slug = self.gates[gate].get("troubleshooting", "")
            return str(own[rule]), f"{TROUBLESHOOTING.as_posix()}#{slug}" if slug in self.slugs else ""
        return None

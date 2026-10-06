#!/usr/bin/env python3
"""A CI job's evidence: what its tools reported, as data the bot can check.

Usage: evidence.py <gate>
       evidence.py --read <tool> <file>

Run as a job's last step but one, whatever the steps before it did. It reads
the gate's entry in .github/gates.json, the raw output each of its steps left
where the entry says (a path from the repository root, evidence/raw/ for most),
and the outcome of each step from STEPS (the job's `toJSON(steps)`). It writes
evidence/<gate>.json (ADR-0019, decision 5):

    {"format": 1, "job": <gate>, "tool": [<tool>, ...],
     "findings": [{"tool", "path", "line", "rule", "detail"}, ...]}

- `path` is spelt from the repository root, and is "" where a finding names
  no file (a commit, a count);
- `line` is 0 where a finding names no line (a whole file);
- `rule` is the tool's own name for what it found: a PHPStan identifier, or
  the package's rule ID where the message names one; a fixer; a Rector class;
- `detail` is the tool's own words, cut short. The bot never renders it, and
  this step prints it as an annotation on the diff.

A step that failed and left nothing readable is one finding whose rule is the
tool's own name, so a gate is never red with no finding.

Two halves: the functions named after the tools decide, from text alone, what
each reported, and `main` reads and writes files.
"""
import json
import os
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

FORMAT = 1

# At most this many findings a job writes, and characters of a detail.
MOST_FINDINGS = 1000
MOST_DETAIL = 300

# A rule ID of this package, as ARCHITECTURE.md writes it, where a message
# leads with one: "H5 — build this with sprintf", "strlen() is forbidden, L3 — ".
PACKAGE_RULE = re.compile(r"(?:^|[ ,])([A-Z][0-9]{1,2}) \u2014")

# A rule ID of this package anywhere in a test's failure: "... (G2)".
NAMED_RULE = re.compile(r"\(([A-Z][0-9]{1,2})\)")

# Where a JUnit failure says it happened: "at tests/Unit/MoneyTest.php:12".
AT = re.compile(r"^at (\S+?):(\d+)$", re.MULTILINE)

# Where the dependency analyser says a class is used: "src/Money.php:12".
USED_AT = re.compile(r"^(\S+?):(\d+)$")


def finding(tool: str, path: str, line: int, rule: str, detail: str = "") -> dict:
    return {"tool": tool, "path": path, "line": line, "rule": rule, "detail": detail[:MOST_DETAIL]}


def relative(path: str, root: str) -> str:
    """A path spelt from the repository root, whatever the tool spelt it from."""
    for prefix in (root.rstrip("/") + "/", "/github/workspace/", "./"):
        if path.startswith(prefix):
            return path[len(prefix):]
    return path


def phpstan(text: str, root: str) -> list[dict]:
    """`phpstan analyse --error-format=json`."""
    found = []
    for path, file in json.loads(text).get("files", {}).items():
        for message in file.get("messages", []):
            words = str(message.get("message", ""))
            named = PACKAGE_RULE.search(words)
            rule = named.group(1) if named else str(message.get("identifier") or "phpstan")
            found.append(finding("phpstan", relative(path, root), int(message.get("line") or 0), rule, words))
    for words in json.loads(text).get("errors", []):
        found.append(finding("phpstan", "", 0, "phpstan", str(words)))
    return found


def pint(text: str, root: str) -> list[dict]:
    """`pint --test --format=json`: the fixers each file needs, with no line."""
    found = []
    for file in json.loads(text).get("files", []):
        for fixer in file.get("fixers", []):
            found.append(finding("pint", relative(file["path"], root), 0, str(fixer)))
    return found


def rector(text: str, root: str) -> list[dict]:
    """`rector process --dry-run --output-format=json`: each rule's change, by line."""
    found = []
    answer = json.loads(text)
    for diff in answer.get("file_diffs", []):
        path = relative(diff["file"], root)
        changes = diff.get("changes") or [{"rector": rule, "line": 0} for rule in diff.get("applied_rectors", [])]
        for change in changes:
            found.append(finding("rector", path, int(change.get("line") or 0), str(change["rector"])))
    for error in answer.get("errors", []):
        found.append(finding("rector", relative(str(error.get("file", "")), root), int(error.get("line") or 0), "rector", str(error.get("message", ""))))
    return found


def xml(text: str) -> ET.Element:
    """An XML report, refused where it declares a document type: no entity is expanded."""
    if "<!DOCTYPE" in text or "<!ENTITY" in text:
        raise ValueError("an XML report declares a document type")
    return ET.fromstring(text)


def junit(text: str, root: str) -> list[dict]:
    """A JUnit log from Pest: each failed or broken test, where it says it broke."""
    found = []
    for case in xml(text).iter("testcase"):
        for outcome in ("failure", "error"):
            for element in case.findall(outcome):
                words = element.text or ""
                file = relative(str(case.get("file", "")).split("::", 1)[0], root)
                at = [(where, line) for where, line in AT.findall(words) if relative(where, root) == file]
                named = NAMED_RULE.search(words)
                rule = named.group(1) if named else outcome
                found.append(finding("pest", file, int(at[-1][1]) if at else 0, rule, str(case.get("name", ""))))
    return found


def clover(text: str, root: str) -> list[dict]:
    """A Clover report: every statement no test ran."""
    found = []
    for file in xml(text).iter("file"):
        path = relative(str(file.get("name", "")), root)
        for line in file.iter("line"):
            if line.get("type") == "stmt" and line.get("count") == "0":
                found.append(finding("coverage", path, int(line.get("num", "0")), "uncovered"))
    return found


def normalize(text: str, root: str) -> list[dict]:
    """`composer normalize --dry-run`: a diff where composer.json is not normalized."""
    return [finding("composer-normalize", "composer.json", 0, "composer-normalize")] if "@@" in text else []


def audit(text: str, root: str) -> list[dict]:
    """`composer audit --format=json`: each advisory and each abandoned package."""
    answer = json.loads(text)
    found = []
    advisories = answer.get("advisories") or {}
    for package, listed in (advisories.items() if isinstance(advisories, dict) else []):
        for advisory in listed:
            found.append(finding("composer-audit", "composer.lock", 0, "advisory", f"{package} {advisory.get('advisoryId', '')}"))
    abandoned = answer.get("abandoned") or {}
    for package in (abandoned if isinstance(abandoned, dict) else []):
        found.append(finding("composer-audit", "composer.lock", 0, "abandoned", str(package)))
    return found


def dependencies(text: str, root: str) -> list[dict]:
    """`composer-dependency-analyser --format=junit`: each kind of problem, at its first use."""
    found = []
    for suite in xml(text).iter("testsuite"):
        rule = re.sub(r"[^a-z0-9]+", "-", str(suite.get("name", "")).lower()).strip("-")
        for case in suite.iter("testcase"):
            for failure in case.findall("failure"):
                used = USED_AT.match((failure.text or "").strip())
                path, line = (relative(used.group(1), root), int(used.group(2))) if used else ("composer.json", 0)
                found.append(finding("dependency-analyser", path, line, rule, str(case.get("name", ""))))
    return found


def typos(text: str, root: str) -> list[dict]:
    """`typos --format json`: one object per line, for each typo."""
    found = []
    for line in filter(None, text.splitlines()):
        typo = json.loads(line)
        if typo.get("type") == "typo":
            corrections = ", ".join(typo.get("corrections", []))
            found.append(finding("typos", relative(typo["path"], root), int(typo.get("line_num") or 0), "typo", f"{typo.get('typo', '')} -> {corrections}"))
    return found


def markdownlint(text: str, root: str) -> list[dict]:
    """markdownlint-cli2's JSON formatter: each rule broken, by line."""
    found = []
    for result in json.loads(text):
        names = result.get("ruleNames") or ["markdownlint"]
        detail = str(result.get("ruleDescription", ""))
        found.append(finding("markdownlint", relative(result["fileName"], root), int(result.get("lineNumber") or 0), str(names[0]), detail))
    return found


def actionlint(text: str, root: str) -> list[dict]:
    """`actionlint -format '{{json .}}'`: each error, by its kind."""
    return [
        finding("actionlint", relative(error["filepath"], root), int(error.get("line") or 0), str(error.get("kind", "actionlint")), str(error.get("message", "")))
        for error in json.loads(text) or []
    ]


def zizmor(text: str, root: str) -> list[dict]:
    """`zizmor --format=json`: each finding not ignored, by its audit, where its primary location is."""
    found = []
    for item in json.loads(text) or []:
        if item.get("ignored"):
            continue
        locations = item.get("locations") or [{}]
        primary = next((place for place in locations if (place.get("symbolic") or {}).get("kind") == "Primary"), locations[0])
        symbolic = primary.get("symbolic") or {}
        path = ((symbolic.get("key") or {}).get("Local") or {}).get("verbatim_path", "")
        row = (((primary.get("concrete") or {}).get("location") or {}).get("start_point") or {}).get("row")
        line = int(row) + 1 if isinstance(row, int) else 0
        found.append(finding("zizmor", relative(str(path), root), line, str(item.get("ident", "zizmor")), str(symbolic.get("annotation") or item.get("desc", ""))))
    return found


def lychee(text: str, root: str) -> list[dict]:
    """`lychee --format json`: each link that failed or timed out, where it is written."""
    answer = json.loads(text)
    found = []
    for field in ("error_map", "timeout_map"):
        for path, links in (answer.get(field) or {}).items():
            for link in links:
                span = link.get("span") or {}
                found.append(finding("lychee", relative(path, root), int(span.get("line") or 0), "broken-link", str(link.get("url", ""))))
    return found


def gitleaks(text: str, root: str) -> list[dict]:
    """gitleaks' redacted JSON report: each secret where it was committed, its rule and commit in the detail."""
    return [
        finding("gitleaks", relative(str(leak.get("File", "")), root), int(leak.get("StartLine") or 0), "secret", f"{leak.get('RuleID', '')} {str(leak.get('Commit', ''))[:12]}")
        for leak in json.loads(text) or []
    ]


def osv(text: str, root: str) -> list[dict]:
    """`osv-scanner --format json`: each vulnerable package, in the lock file that installs it."""
    found = []
    for result in json.loads(text).get("results") or []:
        path = relative(str((result.get("source") or {}).get("path", "")), root)
        for package in result.get("packages") or []:
            name = (package.get("package") or {}).get("name", "")
            for vulnerability in package.get("vulnerabilities") or []:
                found.append(finding("osv-scanner", path, 0, "vulnerability", f"{name} {vulnerability.get('id', '')}"))
    return found


def own(tool: str):
    """The findings one of this repository's scripts wrote, as it wrote them."""

    def read(text: str, root: str) -> list[dict]:
        return [finding(tool, str(f.get("path", "")), int(f.get("line") or 0), str(f["rule"]), str(f.get("detail", ""))) for f in json.loads(text)]

    return read


# What reads each tool's raw output. A tool not listed here reports only
# through its step's outcome, as `composer validate` does.
READERS = {
    "phpstan": phpstan,
    "pint": pint,
    "rector": rector,
    "pest": junit,
    "coverage": clover,
    "composer-normalize": normalize,
    "composer-audit": audit,
    "dependency-analyser": dependencies,
    "typos": typos,
    "markdownlint": markdownlint,
    "actionlint": actionlint,
    "zizmor": zizmor,
    "lychee": lychee,
    "gitleaks": gitleaks,
    "osv-scanner": osv,
    "commitlint": own("commitlint"),
    "attribution": own("attribution"),
    "sonar": own("sonar"),
    "scripts": own("scripts"),
    "description": own("description"),
    "pins": own("pins"),
    "warm": own("warm"),
}


def collect(producers: list[dict], outcomes: dict, raw: dict, root: str) -> tuple[list[str], list[dict]]:
    """What a job's steps reported: the tools read, and their findings.

    `producers` are the gate's evidence entries, `outcomes` each step's outcome
    by its id, and `raw` each raw file's text by its name, where it exists.
    """
    tools = []
    found = []
    for producer in producers:
        tool, step = producer["tool"], producer["step"]
        outcome = str((outcomes.get(step) or {}).get("outcome", "skipped"))
        if outcome == "skipped":
            continue
        tools.append(tool)
        text = raw.get(producer.get("raw", ""))
        read = []
        if text is not None and tool in READERS:
            try:
                read = READERS[tool](text, root)
            except (ValueError, KeyError, TypeError, AttributeError, ET.ParseError):
                read = []
        if outcome == "failure" and not read:
            read = [finding(tool, "", 0, tool, f"the step {step} failed")]
        found.extend(read)
    return tools, found[:MOST_FINDINGS]


def annotation(item: dict) -> str:
    """A finding as a workflow command, so the job shows it on the diff."""

    def data(text: str) -> str:
        return text.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")

    def prop(text: str) -> str:
        return data(text).replace(":", "%3A").replace(",", "%2C")

    where = f"file={prop(item['path'])},line={item['line']}," if item["path"] and item["line"] else (f"file={prop(item['path'])}," if item["path"] else "")
    return f"::error {where}title={prop(item['tool'] + ' ' + item['rule'])}::{data(item['detail'] or item['rule'])}"


def document(gate: str, tools: list[str], found: list[dict]) -> dict:
    return {"format": FORMAT, "job": gate, "tool": tools, "findings": found}


def file_name(gate: str) -> str:
    """The name a gate's evidence goes by: `hygiene/typos` is `hygiene-typos`."""
    return gate.replace("/", "-")


def main(argv: list[str]) -> int:
    if argv[1] == "--read":
        # What one tool's raw output reports, for the tests that tie the
        # recorded fixtures to the rule tables.
        print(json.dumps(READERS[argv[2]](Path(argv[3]).read_text(encoding="utf-8"), os.getcwd())))
        return 0
    gate = argv[1]
    gates = json.loads(Path(".github/gates.json").read_text(encoding="utf-8"))["gates"]
    producers = gates[gate]["evidence"]
    producers = producers if isinstance(producers, list) else []
    directory = Path("evidence")
    raw = {}
    for producer in producers:
        name = producer.get("raw")
        if name and Path(name).is_file():
            raw[name] = Path(name).read_text(encoding="utf-8", errors="replace")
    outcomes = json.loads(os.environ.get("STEPS") or "{}")
    tools, found = collect(producers, outcomes, raw, os.getcwd())
    directory.mkdir(exist_ok=True)
    (directory / f"{file_name(gate)}.json").write_text(json.dumps(document(gate, tools, found), indent=1) + "\n", encoding="utf-8")
    for item in found:
        print(annotation(item))
    print(f"evidence: {len(found)} finding(s) from {', '.join(tools) or 'no tool'}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))

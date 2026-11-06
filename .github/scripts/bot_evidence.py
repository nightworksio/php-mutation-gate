"""A run's `evidence` artifact, read as hostile as the pull request that wrote it (ADR-0019).

The artifact is downloaded through the API and unpacked in memory. Only the
file names main's gates.json expects are read, `<gate>.json` and, for a gate
with a fix, `<gate>.fix.patch`, and no path is taken from the archive. Each
file and the whole are held to a size cap, and each document to its schema:
a document that fails is dropped whole. What reaches the explainer is a
finding's tool, path, line and rule; the tool's own words never do.

Two halves: the functions above `fetch` decide from bytes alone, and `fetch`
asks GitHub.
"""
import io
import json
import re
import zipfile

import bot_patch
import github_api

FORMAT = 1

# A rule as the tools write one: an identifier, a fixer, a Rector class.
RULE = re.compile(r"\A[A-Za-z0-9_.\\-]{1,200}\Z")


def file_name(gate: str) -> str:
    """The name a gate's evidence goes by: `hygiene/typos` is `hygiene-typos`."""
    return gate.replace("/", "-")


def expected(gates: dict) -> dict[str, tuple[str, str]]:
    """Every name the artifact may hold, with the gate and the kind of file it is."""
    names = {}
    for gate, entry in gates.items():
        names[f"{file_name(gate)}.json"] = (gate, "findings")
        if entry.get("fix"):
            names[f"{file_name(gate)}.fix.patch"] = (gate, "patch")
    return names


def unpack(archive: bytes, names: dict, most_file: int, most_total: int) -> dict[str, bytes]:
    """The expected files of a zip archive, each and all within their caps; an archive that is not one is empty."""
    try:
        opened = zipfile.ZipFile(io.BytesIO(archive))
        entries = opened.infolist()
    except (zipfile.BadZipFile, ValueError):
        return {}
    files = {}
    total = 0
    for entry in entries:
        if entry.filename not in names or entry.filename in files or entry.file_size > most_file:
            continue
        try:
            with opened.open(entry) as member:
                data = member.read(most_file + 1)
        except (zipfile.BadZipFile, ValueError, NotImplementedError, RuntimeError, OSError):
            continue
        total += len(data)
        if len(data) > most_file or total > most_total:
            break
        files[entry.filename] = data
    return files


def findings(gate: str, tools: list[str], data: bytes) -> list[dict] | None:
    """A gate's findings, each a tool the gate runs, a path, a line and a rule; None where the document is not its schema."""
    try:
        document = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, ValueError):
        return None
    if not isinstance(document, dict) or document.get("format") != FORMAT or document.get("job") != gate:
        return None
    listed = document.get("findings")
    if not isinstance(listed, list):
        return None
    found = []
    for item in listed:
        if not _valid(item, tools):
            return None
        found.append({"tool": item["tool"], "path": item["path"], "line": item["line"], "rule": item["rule"]})
    return found


def _valid(item, tools: list[str]) -> bool:
    if not isinstance(item, dict):
        return False
    tool, path, line, rule = item.get("tool"), item.get("path"), item.get("line"), item.get("rule")
    return (
        tool in tools
        and isinstance(path, str)
        and (path == "" or bot_patch.safe_path(path))
        and isinstance(line, int)
        and not isinstance(line, bool)
        and 0 <= line < 10_000_000
        and isinstance(rule, str)
        and RULE.match(rule) is not None
    )


def patch(data: bytes) -> list[bot_patch.FilePatch] | None:
    """A gate's fix, file by file; None where it is not a patch the bot applies."""
    try:
        return bot_patch.parse(data.decode("utf-8"))
    except (UnicodeDecodeError, bot_patch.Refused):
        return None


def read(files: dict[str, bytes], names: dict, tools_of) -> tuple[dict, dict]:
    """The findings and the fixes of every gate whose files are valid."""
    found, fixes = {}, {}
    for name, data in files.items():
        gate, kind = names[name]
        if kind == "findings":
            listed = findings(gate, tools_of(gate), data)
            if listed is not None:
                found[gate] = listed
        else:
            patches = patch(data)
            if patches:
                fixes[gate] = patches
    return found, fixes


def fetch(run: int, names: dict, limits: dict) -> dict[str, bytes]:
    """The expected files of a run's `evidence` artifact, or none where it has no such artifact."""
    artifacts = github_api.call("GET", f"actions/runs/{int(run)}/artifacts?name=evidence&per_page=10")["artifacts"]
    usable = [item for item in artifacts if item.get("name") == "evidence" and not item.get("expired")]
    if not usable or int(usable[0].get("size_in_bytes", 0)) > limits["most_archive_bytes"]:
        return {}
    return unpack(github_api.archive(int(usable[0]["id"])), names, limits["most_file_bytes"], limits["most_total_bytes"])

"""A fix CI computed, as `git diff` wrote it: read, held to the head, and cut into suggestions.

The deciding half of the suggested changes and of `/fix` (ADR-0019). A patch
comes from a pull request's own CI, so it is read as hostile: it may only
change lines of files that exist, it never creates, deletes, renames or
changes the mode of a file, and every hunk must match the head's text
exactly where it says it applies. The job ran on the merge of the pull request
into its base, so a hunk the base moved does not match and is refused.
"""
import re
from dataclasses import dataclass

# A path the bot names: no `..` segment, no leading slash, and only the
# characters this repository's files are named with.
PATH = re.compile(r"\A(?!/)(?!(?:.*/)?\.\.(?:/|\Z))[A-Za-z0-9._+/-]{1,300}\Z")

FILE = re.compile(r"\Adiff --git a/(\S+) b/(\S+)\Z")
HUNK = re.compile(r"\A@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@")

# The lines of a file header git writes between `diff --git` and the first hunk.
HEADER_LINES = ("index ", "--- ", "+++ ")


class Refused(Exception):
    """Why a patch, or a hunk of it, is not what the bot applies."""


@dataclass(frozen=True)
class Hunk:
    old_start: int
    lines: tuple[str, ...]

    @property
    def old(self) -> list[str]:
        return [line[1:] for line in self.lines if line[0] in " -"]

    @property
    def new(self) -> list[str]:
        return [line[1:] for line in self.lines if line[0] in " +"]


@dataclass(frozen=True)
class FilePatch:
    path: str
    hunks: tuple[Hunk, ...]


@dataclass(frozen=True)
class Suggestion:
    """Lines start..end of the head's file, replaced by `text`."""

    path: str
    start: int
    end: int
    text: tuple[str, ...]


def safe_path(path: str) -> bool:
    return PATH.match(path) is not None


def parse(text: str) -> list[FilePatch]:
    """Each file a patch changes, and its hunks; refused where it does more than change lines."""
    files: list[FilePatch] = []
    lines = text.split("\n")
    if lines and lines[-1] == "":
        lines.pop()
    index = 0
    while index < len(lines):
        named = FILE.match(lines[index])
        if named is None or named[1] != named[2] or not safe_path(named[1]):
            raise Refused("a file header is not one this bot applies")
        index += 1
        while index < len(lines) and lines[index].startswith(HEADER_LINES):
            index += 1
        hunks = []
        while index < len(lines) and lines[index].startswith("@@"):
            hunk, index = _hunk(lines, index)
            hunks.append(hunk)
        if not hunks:
            raise Refused(f"{named[1]}: no hunk, or a change that is not of lines")
        files.append(FilePatch(named[1], tuple(hunks)))
    return files


def _hunk(lines: list[str], index: int) -> tuple[Hunk, int]:
    header = HUNK.match(lines[index])
    if header is None:
        raise Refused("a hunk header is not git's")
    old_count = int(header[2] if header[2] is not None else 1)
    new_count = int(header[4] if header[4] is not None else 1)
    body = []
    index += 1
    while index < len(lines) and (old_count > 0 or new_count > 0):
        line = lines[index]
        kind = line[:1]
        if kind == "\\":
            raise Refused("a hunk changes whether a file ends with a newline")
        if kind not in (" ", "-", "+"):
            raise Refused("a hunk is shorter than its header says")
        old_count -= kind in " -"
        new_count -= kind in " +"
        body.append(line)
        index += 1
    if index < len(lines) and lines[index].startswith("\\"):
        raise Refused("a hunk changes whether a file ends with a newline")
    if old_count or new_count:
        raise Refused("a hunk is shorter than its header says")
    return Hunk(int(header[1]), tuple(body)), index


def matches(hunk: Hunk, text: list[str]) -> bool:
    """Whether the head's lines are, where the hunk applies, the ones it replaces."""
    start = max(hunk.old_start, 1) - 1
    return text[start:start + len(hunk.old)] == hunk.old


def apply(patch: FilePatch, text: str) -> str:
    """The file with every hunk applied; refused where one does not match the head."""
    original = text.split("\n")
    out: list[str] = []
    cursor = 0
    for hunk in patch.hunks:
        start = max(hunk.old_start, 1) - 1
        if start < cursor or not matches(hunk, original):
            raise Refused(f"{patch.path}: a hunk does not match the head")
        out.extend(original[cursor:start])
        out.extend(hunk.new)
        cursor = start + len(hunk.old)
    out.extend(original[cursor:])
    return "\n".join(out)


def suggestions(patch: FilePatch, text: str) -> list[Suggestion]:
    """Each run of changed lines of a patch that matches the head, as lines of the head and their replacement."""
    head = text.split("\n")
    found = []
    for hunk in patch.hunks:
        if not matches(hunk, head):
            continue
        found.extend(_groups(patch.path, hunk))
    return found


def _groups(path: str, hunk: Hunk) -> list[Suggestion]:
    """A hunk cut at its context lines into the runs it changes; an insertion takes the line above it."""
    groups = []
    old_line = hunk.old_start
    index = 0
    lines = hunk.lines
    while index < len(lines):
        if lines[index][0] == " ":
            old_line += 1
            index += 1
            continue
        first, start = index, old_line
        removed, added = [], []
        while index < len(lines) and lines[index][0] != " ":
            if lines[index][0] == "-":
                removed.append(lines[index][1:])
                old_line += 1
            else:
                added.append(lines[index][1:])
            index += 1
        if removed:
            groups.append(Suggestion(path, start, start + len(removed) - 1, tuple(added)))
        elif first > 0:
            above = lines[first - 1][1:]
            groups.append(Suggestion(path, start - 1, start - 1, (above, *added)))
    return groups


def diff_ranges(patch: str) -> list[tuple[int, int]]:
    """The head's lines a pull request's diff of one file shows, from its hunk headers."""
    ranges = []
    for line in patch.split("\n"):
        header = HUNK.match(line)
        if header:
            start = int(header[3])
            count = int(header[4] if header[4] is not None else 1)
            if count:
                ranges.append((start, start + count - 1))
    return ranges


def inside(suggestion: Suggestion, ranges: list[tuple[int, int]]) -> bool:
    """Whether a suggestion's lines all lie in one hunk of the pull request's diff."""
    return any(low <= suggestion.start and suggestion.end <= high for low, high in ranges)


def render(patch: FilePatch) -> str:
    """A file's patch, as git wrote it, for the collapsed part of the comment."""
    out = [f"--- a/{patch.path}", f"+++ b/{patch.path}"]
    for hunk in patch.hunks:
        out.append(f"@@ -{hunk.old_start},{len(hunk.old)} +{hunk.old_start},{len(hunk.new)} @@")
        out.extend(hunk.lines)
    return "\n".join(out)

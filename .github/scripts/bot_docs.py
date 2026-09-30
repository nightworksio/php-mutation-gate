"""The heading index `/docs` searches: every heading of `.docs`, and its anchor.

The deciding half of `/docs` (ADR-0019, decision 13). The words a comment
asks with are reduced to `[a-z0-9]+` tokens before anything reads them, and
the reply holds only links to headings of `main`'s own documentation.
"""
import re
from pathlib import Path

# The most tokens, and the longest token, a question is cut to.
MAX_TOKENS = 8
MAX_TOKEN = 32

# The most links a reply holds.
MAX_LINKS = 3

HEADING = re.compile(r"^(#{1,6})[ \t]+(.+?)[ \t#]*$")
FENCE = re.compile(r"^[ \t]*(```|~~~)")
LINK = re.compile(r"\[([^\]]*)\]\([^)]*\)")
TOKEN = re.compile(r"[a-z0-9]+")


def tokens(words: str) -> list[str]:
    """The `[a-z0-9]+` tokens of what was asked, lowercased, deduplicated, and cut to size."""
    found = []
    for token in TOKEN.findall(words.lower()):
        if len(token) <= MAX_TOKEN and token not in found:
            found.append(token)
    return found[:MAX_TOKENS]


def plain(heading: str) -> str:
    """A heading as it reads: a link's text in place of the link, and no code marks."""
    return LINK.sub(r"\1", heading).replace("`", "")


def anchor(heading: str, seen: dict[str, int]) -> str:
    """The anchor GitHub gives a heading, counting the ones already seen on the page."""
    slug = re.sub(r"[^\w\- ]", "", plain(heading).lower()).replace(" ", "-")
    count = seen.get(slug, 0)
    seen[slug] = count + 1
    return slug if count == 0 else f"{slug}-{count}"


def headings(text: str) -> list[tuple[str, str]]:
    """Every heading of a Markdown page outside a code fence, with its anchor."""
    found = []
    seen: dict[str, int] = {}
    fenced = False
    for line in text.splitlines():
        if FENCE.match(line):
            fenced = not fenced
            continue
        match = None if fenced else HEADING.match(line)
        if match:
            found.append((plain(match[2]), anchor(match[2], seen)))
    return found


def index(root: Path) -> list[tuple[str, str, str]]:
    """Every heading under `.docs`, as its page's path, its text and its anchor."""
    return [
        (page.relative_to(root.parent).as_posix(), text, slug)
        for page in sorted(root.rglob("*.md"))
        for text, slug in headings(page.read_text(encoding="utf-8"))
    ]


def search(entries: list[tuple[str, str, str]], asked: list[str]) -> list[tuple[str, str, str]]:
    """The headings most of the tokens name, best first, and in page order between equals."""
    scored = []
    for position, (path, text, slug) in enumerate(entries):
        words = set(TOKEN.findall(f"{text} {Path(path).stem}".lower()))
        score = sum(1 for token in asked if token in words)
        if score:
            scored.append((-score, position, (path, text, slug)))
    return [entry for _, _, entry in sorted(scored)[:MAX_LINKS]]

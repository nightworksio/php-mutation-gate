"""The line, and the section, a pull request adds to CHANGELOG.md once it is merged (ADR-0019).

The squash merge makes the title the commit's subject and the body its
message, and the release's git-cliff run (ADR-0018) groups that commit by
main's cliff.toml. The groups are read from cliff.toml itself, and the line is
written as its template writes one: the scope in bold, then the subject with
its first letter raised, and under *Breaking* the body's *Migration* section
quoted below it.
"""
import re
import tomllib

TITLE = re.compile(r"\A(?P<type>[a-z]+)(?:\((?P<scope>[^)]*)\))?(?P<breaking>!)?: (?P<subject>.+)\Z")
BREAKING_FOOTER = re.compile(r"^BREAKING[ -]CHANGE: ", re.MULTILINE)

# A group's sort key, which cliff.toml hides in an HTML comment before its name.
SORT_KEY = re.compile(r"<!--.*?-->")


def groups(cliff: str) -> tuple[str, list[tuple[re.Pattern, str]]]:
    """The group a breaking change goes in, and each other group with the subjects it takes."""
    breaking = ""
    by_message = []
    for parser in tomllib.loads(cliff)["git"]["commit_parsers"]:
        name = SORT_KEY.sub("", parser["group"]).strip()
        if parser.get("field") == "breaking":
            breaking = name
        elif "message" in parser:
            by_message.append((re.compile(parser["message"]), name))
    return breaking, by_message


def preview(title: str, body: str, cliff: str) -> tuple[str, str] | None:
    """The section and the text the merged pull request adds, or None where git-cliff leaves it out."""
    body = body.replace("\r\n", "\n")
    parsed = TITLE.match(title.strip())
    if parsed is None:
        return None
    breaking_group, by_message = groups(cliff)
    breaking = parsed["breaking"] is not None or BREAKING_FOOTER.search(body) is not None
    section = breaking_group if breaking else next((name for pattern, name in by_message if pattern.search(title)), "")
    if not section:
        return None
    subject = parsed["subject"].strip()
    line = "- " + (f"**{parsed['scope']}:** " if parsed["scope"] else "") + subject[:1].upper() + subject[1:]
    if breaking and "## Migration" in body:
        migration = body.split("## Migration")[-1].split("\n## ")[0].strip()
        line += "\n\n" + "\n".join(f"  > {text}".rstrip() for text in migration.split("\n"))
    return section, line

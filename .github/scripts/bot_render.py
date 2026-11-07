"""The sticky comment's text, from what the explainer collected (ADR-0019).

The deciding half of the explainer, the checklist and the welcome. Every
sentence is one of main's tables, filled with a value that was validated where
it was read:

- numbers, SHAs and URLs GitHub answered;
- paths of the files the pull request changes, in the bot's safe characters;
- rules in main's vocabulary, explained in main's words.

The pull request's own text reaches the comment twice, inside a code block:
the changelog line its title makes, and the hunks of the fix its CI computed.
A code block renders no Markdown, link or mention.
"""
import re
from dataclasses import dataclass, field

import bot_changelog
import bot_patch
import bot_sticky
import bot_tables
import commit_lint
import pr_text

BACKTICKS = re.compile(r"`+")

# The conclusions of a job or a run that fail it.
FAILED = ("failure", "timed_out", "startup_failure")

# The conclusions of a run that leave nothing to explain.
PASSED = ("success", "neutral", "skipped")


@dataclass(frozen=True)
class Pull:
    number: int
    sha: str
    draft: bool
    association: str
    title: str
    body: str
    same_repo: bool


@dataclass(frozen=True)
class Run:
    key: str
    id: int
    status: str
    conclusion: str
    url: str


@dataclass(frozen=True)
class Job:
    gate: str
    conclusion: str
    url: str


@dataclass(frozen=True)
class Reran:
    name: str
    url: str
    kind: str


@dataclass
class State:
    pull: Pull
    files: dict[str, list[tuple[int, int]]]
    runs: dict[str, Run | None]
    waiting: bool = False
    jobs: dict[str, list[Job]] = field(default_factory=dict)
    findings: dict[str, list[dict]] = field(default_factory=dict)
    fixes: dict[str, list[bot_patch.FilePatch]] = field(default_factory=dict)
    heads: dict[str, str] = field(default_factory=dict)
    gate_comment: str = ""
    reran: list[Reran] = field(default_factory=list)
    suggested: str = ""
    blob: str = ""


def code(text: str) -> str:
    """Text as inline code, whatever backticks it holds."""
    longest = max((len(run) for run in BACKTICKS.findall(text)), default=0)
    ticks = "`" * (longest + 1)
    return f"{ticks} {text} {ticks}" if longest else f"`{text}`"


def fence(text: str, info: str = "") -> str:
    """Text as a fenced code block, whatever fences it holds."""
    longest = max((len(run) for run in BACKTICKS.findall(text)), default=0)
    ticks = "`" * max(3, longest + 1)
    return f"{ticks}{info}\n{text}\n{ticks}"


def link_of(target: str, blob: str) -> str:
    """A link main's tables give: a page on the web, or a file of main with its anchor."""
    return target if target.startswith("https://") else f"{blob}/{target}"


def check_name(entry: dict) -> str:
    """A gate's job name as main writes it, with the expressions a run fills in left out."""
    return bot_tables.EXPRESSION.sub("…", entry["check"])


def failed_gates(state: State) -> tuple[list[tuple[str, str]], int]:
    """Each gate a relayed run failed, with one of its jobs, and how many failed jobs main has no gate for."""
    gates, others = [], 0
    for key in sorted(state.jobs):
        for job in state.jobs[key]:
            if job.conclusion not in FAILED:
                continue
            if not job.gate:
                others += 1
            elif job.gate not in (gate for gate, _ in gates):
                gates.append((job.gate, job.url))
    return gates, others


def headline(state: State, tables: bot_tables.Tables, failed: int) -> str:
    sha = state.pull.sha[:7]
    runs = [run for run in state.runs.values() if run is not None]
    if failed:
        return tables.say("headline-red", sha=sha, count=str(failed))
    if state.waiting:
        return tables.say("headline-waiting", sha=sha)
    if len(runs) < len(state.runs) or any(run.status != "completed" for run in runs):
        return tables.say("headline-running", sha=sha)
    return tables.say("headline-green", sha=sha)


def changed_paths(state: State) -> list[str]:
    return sorted(state.files)


def checklist(state: State, tables: bot_tables.Tables) -> list[tuple[bool, str]]:
    """Each item of the checklist that applies, and whether it is done."""
    items = tables.words["checklist"]
    welcomed = state.pull.association in tables.config["checklist"]["welcomed"]
    found = []
    if welcomed or state.waiting:
        found.append((not state.waiting, items["approved"]))
    found.append((commit_lint.conventional(state.pull.title), items["title"]))
    runs = list(state.runs.values())
    green = all(run is not None and run.status == "completed" and run.conclusion in PASSED for run in runs)
    found.append((green, items["gates"]))
    verdict = [job for job in state.jobs.get("ci", []) if job.gate == tables.config["comment"]["verdict_gate"]]
    if verdict:
        found.append((all(job.conclusion in PASSED for job in verdict), items["tested"]))
    specs = pr_text.mapped_specs(changed_paths(state), tables.spec_map)
    if specs:
        named = pr_text.spec_numbers(state.pull.body)
        done = bool(named) and not pr_text.unknown_specs(named, set(tables.decisions))
        found.append((done, {**items["spec"], "values": {"specs": decisions(specs, tables, state.blob)}}))
    found.append((not state.pull.draft, items["ready"]))
    return [(done, bot_tables.fill(item["done" if done else "todo"], item.get("values", {}))) for done, item in found]


def decisions(numbers: list[str], tables: bot_tables.Tables, blob: str) -> str:
    return ", ".join(
        tables.say("decision", number=number, link=link_of(tables.decisions[number], blob))
        for number in numbers
        if number in tables.decisions
    )


def render_checklist(items: list[tuple[bool, str]], tables: bot_tables.Tables) -> str:
    todo = [f"- [ ] {text}" for done, text in items if not done]
    done = [f"- [x] {text}" for done, text in items if done]
    out = "\n".join(todo)
    if done:
        summary = tables.say("done", count=str(len(done)))
        out += ("\n\n" if out else "") + f"<details><summary>{summary}</summary>\n\n" + "\n".join(done) + "\n\n</details>"
    return out


def explained(gate: str, state: State, tables: bot_tables.Tables, most: int) -> list[str]:
    """A gate's findings in main's words, grouped by file and rule, and what was left out."""
    groups: dict[tuple[str, str], dict] = {}
    outside = unrecognised = 0
    for item in state.findings.get(gate, []):
        if item["path"] and item["path"] not in state.files and not _beyond(gate, item["path"], tables):
            outside += 1
            continue
        explanation = tables.explanation(gate, item["tool"], item["rule"])
        if explanation is None:
            unrecognised += 1
            continue
        group = groups.setdefault((item["path"], item["rule"]), {"lines": set(), "explanation": explanation})
        if item["line"]:
            group["lines"].add(item["line"])
    keys = sorted(groups)
    lines = [_finding(path, rule, groups[(path, rule)], tables, state.blob) for path, rule in keys[:most]]
    if len(keys) > most:
        lines.append(tables.say("cut", count=str(len(keys) - most)))
    if unrecognised:
        lines.append(tables.say("unrecognised", count=str(unrecognised)))
    if outside:
        lines.append(tables.say("outside", count=str(outside)))
    return lines


def _beyond(gate: str, path: str, tables: bot_tables.Tables) -> bool:
    return path in tables.config["fix"]["beyond_the_diff"].get(gate, [])


def _finding(path: str, rule: str, group: dict, tables: bot_tables.Tables, blob: str) -> str:
    says, link = group["explanation"]
    says = f"{says} {tables.say('more', link=link_of(link, blob))}" if link else says
    rule = rule.replace("`", "")
    if group["lines"]:
        listed = ", ".join(str(line) for line in sorted(group["lines"]))
        return tables.say("finding-lines", path=path, lines=listed, rule=rule, says=says)
    if path:
        return tables.say("finding-file", path=path, rule=rule, says=says)
    return tables.say("finding-plain", rule=rule, says=says)


def gate_section(gate: str, job_url: str, state: State, tables: bot_tables.Tables, most: int) -> str:
    entry = tables.gates[gate]
    out = [tables.say("gate-failed", check=check_name(entry)), "", entry.get("checks", "")]
    if gate == tables.config["comment"]["verdict_gate"]:
        verdict = (
            tables.say("verdict", comment=state.gate_comment)
            if state.gate_comment
            else tables.say("verdict-run", run=job_url)
        )
        return "\n".join([*out, "", verdict])
    findings = explained(gate, state, tables, most)
    if not findings and bot_tables.producers(entry):
        finished = all(run is None or run.status == "completed" for run in state.runs.values())
        findings = [tables.say("no-evidence", job=job_url) if finished else tables.say("evidence-pending")]
    if findings:
        out += ["", *findings]
    out += ["", tables.say("reproduce", command=entry["reproduce"])]
    if entry.get("fix"):
        out.append(tables.say("fix-here" if state.pull.same_repo else "fix-fork", command=entry["fix"]))
    else:
        out.append(tables.say("fix-none"))
    return "\n".join(out)


def fixable(gate: str, patch: bot_patch.FilePatch, state: State, tables: bot_tables.Tables) -> bool:
    """Whether a file a gate's fix changes is one the bot may show or commit."""
    never = tables.config["fix"]["never"]
    in_reach = patch.path in state.files or _beyond(gate, patch.path, tables)
    return in_reach and not patch.path.startswith(tuple(never))


def split_fixes(state: State, tables: bot_tables.Tables) -> tuple[list[bot_patch.Suggestion], list[bot_patch.FilePatch]]:
    """The fixes, as suggestions where they lie in the diff and match the head, and as patches where not."""
    inside, outside = [], []
    for gate in sorted(state.fixes):
        for patch in state.fixes[gate]:
            if not fixable(gate, patch, state, tables):
                continue
            head = state.heads.get(patch.path)
            rest = []
            for hunk in patch.hunks:
                one = bot_patch.FilePatch(patch.path, (hunk,))
                found = bot_patch.suggestions(one, head) if head is not None else []
                ranges = state.files.get(patch.path, [])
                if found and all(bot_patch.inside(suggestion, ranges) for suggestion in found):
                    inside.extend(found)
                else:
                    rest.append(hunk)
            if rest:
                outside.append(bot_patch.FilePatch(patch.path, tuple(rest)))
    return inside, outside


def suggestion_body(suggestion: bot_patch.Suggestion) -> str:
    return fence("\n".join(suggestion.text), "suggestion")


def reran_line(state: State, tables: bot_tables.Tables) -> str:
    if not state.reran:
        return ""
    jobs = ", ".join(
        tables.say("reran-job", name=job.name, job=job.url, why=tables.words["reasons"][job.kind]) for job in state.reran
    )
    running = any(run is not None and run.status != "completed" for run in state.runs.values())
    return tables.say("rerunning" if running else "reran", jobs=jobs)


def text_help(state: State, tables: bot_tables.Tables) -> list[str]:
    """The changelog preview, and the title and `Spec:` suggestions."""
    out = []
    paths = changed_paths(state)
    if commit_lint.conventional(state.pull.title):
        preview = bot_changelog.preview(state.pull.title, state.pull.body, tables.cliff)
        if preview is None:
            out.append(tables.say("changelog-none"))
        else:
            out.append(tables.say("changelog", section=preview[0]) + "\n\n" + fence(preview[1], "markdown"))
    else:
        out.append(tables.say("suggest-title", title=pr_text.suggested_title(paths)))
    specs = pr_text.mapped_specs(paths, tables.spec_map)
    if specs and not pr_text.spec_numbers(state.pull.body):
        out.append(tables.say("suggest-spec", specs=decisions(specs, tables, state.blob)))
    return out


def render(state: State, tables: bot_tables.Tables, outside: list[bot_patch.FilePatch], suggested: str) -> str:
    """The whole sticky comment, its findings cut until it fits the cap on a comment."""
    cap = tables.config["comment"]["most_characters"]
    most = tables.config["comment"]["most_findings_per_gate"]
    body = _body(state, tables, suggested, most)
    while len(body) > cap and most > 1:
        most //= 2
        body = _body(state, tables, suggested, most)
    if outside:
        patches = "\n".join(bot_patch.render(patch) for patch in outside)
        block = f"<details><summary>{tables.say('outside-diff')}</summary>\n\n{fence(patches, 'diff')}\n\n</details>"
        if len(body) + len(block) + 2 > cap:
            block = tables.say("outside-diff-cut", command=_fix_commands(state, tables))
        body += "\n\n" + block
    return body + "\n"


def _body(state: State, tables: bot_tables.Tables, suggested: str, most: int) -> str:
    gates, others = failed_gates(state)
    marks = [bot_sticky.STICKY] + ([bot_sticky.suggested_mark(suggested)] if suggested else [])
    parts = []
    if state.pull.association in tables.config["checklist"]["welcomed"]:
        parts.append(tables.say("welcome", contributing=f"{state.blob}/CONTRIBUTING.md#setup"))
    parts.append(f"### {headline(state, tables, len(gates) + others)}")
    parts.append(render_checklist(checklist(state, tables), tables))
    if any(path.startswith(tuple(tables.config["checklist"]["ci_changes"])) for path in state.files):
        parts.append(tables.say("github-changed"))
    for gate, job_url in gates:
        parts.append(gate_section(gate, job_url, state, tables, most))
    run = state.runs.get("ci") or state.runs.get("pr")
    if others and run is not None:
        parts.append(tables.say("other-jobs", count=str(others), run=run.url))
    reran = reran_line(state, tables)
    if reran:
        parts.append(reran)
    parts.extend(text_help(state, tables))
    return "\n".join(marks) + "\n" + "\n\n".join(parts)


def _fix_commands(state: State, tables: bot_tables.Tables) -> str:
    return " && ".join(tables.gates[gate]["fix"] for gate in sorted(state.fixes))

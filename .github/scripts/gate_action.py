#!/usr/bin/env python3
"""What the action and the reusable workflow decide before and after the gate runs.

Usage:
  gate_action.py guard     refuses an event the gate never runs under
  gate_action.py resolve   the run's scope, ledger cache, gate binary and options:
                           `plan_options` adds the mode's to `options`
  gate_action.py config    from the effective config on stdin: whether it keeps
                           its ledger in the default directory, and whether it
                           runs Pest with the optional patches
  gate_action.py outputs   the verdict, scores, report paths and files, and plan

Every subcommand reads GitHub's environment and writes `key=value` lines to
$GITHUB_OUTPUT, or refuses with exit code 2 and says why.

Two halves: the functions below `main` read the environment and files, and
the ones above decide from what they read. The deciding half is what
test_gate_action.py tests.
"""
import hashlib
import json
import os
import sys

# The events that run the default branch's workflow with a token or a cache
# that can write, over content a pull request supplies (ADR-0019). The gate
# never runs under them, so it never saves a ledger under them (C2.1).
LOW_TRUST = frozenset({"pull_request_target", "issue_comment", "workflow_run"})

# The events whose ref is a branch a run may write the ledger of: none runs
# code from a pull request. The gate's own GitHub plan trusts the same three.
TRUSTED = frozenset({"push", "schedule", "workflow_dispatch"})

# The events `mode: auto` runs in full on (ADR-0005, decision 2).
FULL = frozenset({"schedule", "workflow_dispatch", "release"})

MODES = frozenset({"auto", "full", "changed"})

LEDGERS = ".mutation-gate/ledger"

KEY = "mutation-gate-ledger-"

GATE = "nightworksio/mutation-gate"

# The action's own major version, which the installed gate must share. The
# release pull request of a new major moves it (release.py).
MAJOR = "1"

EXIT_CODES = {0: "passed", 1: "failed", 2: "cannot-judge"}


class Refused(Exception):
    """Why the gate must not run, said to the person reading the job's log."""


def refusal(event: str) -> str | None:
    """Why the gate refuses to run under an event, or nothing where it may."""
    if event in LOW_TRUST:
        return (
            f"The gate does not run on {event}: that event runs the default branch's "
            "workflow with write access over content a pull request supplies. "
            "Run it on pull_request, push or schedule."
        )
    return None


def scope(event: str, ref: str, payload: dict) -> str | None:
    """The ledger scope a run is for: its pull request's, a trusted branch's, or none."""
    number = (payload.get("pull_request") or {}).get("number")
    if isinstance(number, int) and event == "pull_request":
        return f"refs/pull/{number}"
    if event in TRUSTED and ref.startswith("refs/heads/"):
        return ref
    return None


def from_fork(payload: dict) -> bool:
    """Whether a pull request's head is in another repository than its base."""
    pull = payload.get("pull_request") or {}
    head = ((pull.get("head") or {}).get("repo") or {}).get("full_name")
    base = (payload.get("repository") or {}).get("full_name")
    return bool(pull) and head != base


def mode_arguments(mode: str, changed_since: str, event: str, ref: str, payload: dict) -> list[str]:
    """The options that choose what the gate considers: every unit, or what changed since a ref."""
    if mode not in MODES:
        raise Refused(f"mode is {mode!r}; it is auto, full or changed.")
    tag = ref.startswith("refs/tags/")
    if mode == "full" or (mode == "auto" and (event in FULL or tag)):
        return ["--full"]
    if changed_since:
        return [f"--changed-since={changed_since}"]
    base = ((payload.get("pull_request") or {}).get("base") or {}).get("sha")
    if event == "pull_request" and isinstance(base, str) and base:
        return [f"--changed-since={base}"]
    return ["--changed-since=last-passed"]


def cache_prefix(ledger_scope: str) -> str:
    """The prefix of a scope's cache entries. Digests keep one scope's prefix from being another's."""
    return KEY + hashlib.sha256(ledger_scope.encode()).hexdigest() + "-"


def ledger_directory(ledger_scope: str) -> str:
    """Where the directory store keeps a scope's ledger."""
    return f"{LEDGERS}/{ledger_scope}"


def ledgers(event: str, ref: str, payload: dict, default_branch: str, cache: bool) -> dict[str, str]:
    """Which ledgers a run restores and whether it saves its own.

    A run restores its own scope and the default branch's, and saves only its
    own. A fork's pull request restores the default branch's alone and saves
    nothing, and a run with no scope saves nothing (ADR-0007).
    """
    own = scope(event, ref, payload)
    default = f"refs/heads/{default_branch}" if default_branch else ""
    fork = from_fork(payload)
    kept = cache and own is not None and not fork
    restores_default = cache and default != "" and default != own
    return {
        "own_dir": ledger_directory(own) if kept else "",
        "own_prefix": cache_prefix(own) if kept else "",
        "default_dir": ledger_directory(default) if restores_default else "",
        "default_prefix": cache_prefix(default) if restores_default else "",
        "save": "true" if kept and event in TRUSTED | {"pull_request"} else "false",
    }


def binary(root_manifest: dict) -> str:
    """The gate the project runs: its own `bin/` for the package itself, its vendor's otherwise."""
    return "bin/mutation-gate" if root_manifest.get("name") == GATE else "vendor/bin/mutation-gate"


def version_refusal(installed: str, major: str) -> str | None:
    """Why the installed gate is not the action's major version, or nothing where it is."""
    if installed.startswith("dev-") or installed.endswith("-dev"):
        return None
    found = installed.lstrip("v").split(".")[0]
    if found != major:
        return (
            f"This action is mutation-gate {major}.x, and the project installs {installed}. "
            f"Pin the action to the tag of the installed version's major, or require {GATE} ^{major}."
        )
    return None


def keeps_default_store(config: dict) -> bool:
    """Whether the effective config keeps its ledger in the directory the cache holds."""
    store = (config.get("proofs") or {}).get("store") or {}
    path = (store.get("with") or {}).get("path", LEDGERS)
    return store.get("use", "directory") == "directory" and path.rstrip("/") == LEDGERS


def patches_pest(config: dict) -> bool:
    """Whether the effective config runs Pest with the optional patches on (ADR-0004)."""
    return config.get("runner") == "pest" and (config.get("pest") or {}).get("patch") is True


def scores(report: dict) -> dict:
    """Each tree's score and each package's new code's, by path, as the JSON report says them."""
    return {
        "trees": {tree["path"]: tree.get("score") for tree in report.get("trees", [])},
        "newCode": {entry["package"]: entry.get("score") for entry in report.get("newCode", [])},
    }


def report_paths(reports: str, extra: dict[str, str]) -> dict[str, str]:
    """Each report's name and path, from `<name>:<path>` lines and those the action adds."""
    paths = {}
    for line in filter(None, (line.strip() for line in reports.splitlines())):
        name, separator, path = line.partition(":")
        if not separator or not name or not path:
            raise Refused(f"reports holds {line!r}; each line is <name>:<path>.")
        paths[name] = path
    return {**paths, **extra}


def report_files(paths: dict[str, str]) -> str:
    """The reports' paths one to a line, as upload-artifact's `path` takes them."""
    return "\n".join(dict.fromkeys(paths.values()))


def report_options(reports: str) -> list[str]:
    """The `--report` options for the `reports` input's lines."""
    return [f"--report={name}:{path}" for name, path in report_paths(reports, {}).items()]


def verdict(code: int) -> str:
    """The verdict an exit code says."""
    return EXIT_CODES.get(code, "cannot-judge")


def output_lines(values: dict[str, str]) -> str:
    """`$GITHUB_OUTPUT` lines, each value on one line or between delimiters where it spans several."""
    lines = []
    for key, value in values.items():
        if "\n" in value:
            delimiter = "EOF_" + hashlib.sha256(value.encode()).hexdigest()
            lines.append(f"{key}<<{delimiter}\n{value}\n{delimiter}")
        else:
            lines.append(f"{key}={value}")
    return "".join(line + "\n" for line in lines)


def main(argv: list[str]) -> int:
    commands = {"guard": _guard, "resolve": _resolve, "config": _config, "outputs": _outputs}
    if len(argv) != 2 or argv[1] not in commands:
        print(__doc__, file=sys.stderr)
        return 2
    try:
        _write(commands[argv[1]]())
    except Refused as refused:
        print(f"::error::{refused}", file=sys.stderr)
        return 2
    return 0


def _guard() -> dict[str, str]:
    why = refusal(os.environ.get("GITHUB_EVENT_NAME", ""))
    if why is not None:
        raise Refused(why)
    return {}


def _resolve() -> dict[str, str]:
    _guard()
    event = os.environ.get("GITHUB_EVENT_NAME", "")
    ref = os.environ.get("GITHUB_REF", "")
    payload = _payload()
    manifest = _json_file("composer.json")
    gate = binary(manifest)
    installed = os.environ.get("INSTALLED", "")
    why = None if gate.startswith("bin/") else version_refusal(installed, MAJOR)
    if why is not None:
        raise Refused(why)
    arguments = mode_arguments(
        os.environ.get("MODE", "auto"), os.environ.get("CHANGED_SINCE", ""), event, ref, payload
    )
    options = _options()
    kept = ledgers(event, ref, payload, os.environ.get("DEFAULT_BRANCH", ""), os.environ.get("CACHE") == "true")
    return {"gate": gate, "options": json.dumps(options), "plan_options": json.dumps(options + arguments), **kept}


def _config() -> dict[str, str]:
    config = json.load(sys.stdin)
    kept = keeps_default_store(config)
    if not kept and os.environ.get("CACHE") == "true":
        print(f"::notice::The config keeps its ledger outside {LEDGERS}, so the Actions cache is not used.")
    return {
        "default_store": "true" if kept else "false",
        "pest_patch": "true" if patches_pest(config) else "false",
    }


def _outputs() -> dict[str, str]:
    report_file = os.environ["REPORT"]
    report = _json_file(report_file) if os.path.isfile(report_file) else {}
    paths = report_paths(os.environ.get("REPORTS", ""), {"json": report_file})
    return {
        "verdict": verdict(int(os.environ.get("EXIT_CODE", "2"))),
        "scores": json.dumps(scores(report)),
        "report_paths": json.dumps(paths),
        "report_files": report_files(paths),
        "plan": os.environ.get("PLAN", ""),
    }


def _options() -> list[str]:
    options = []
    if os.environ.get("CONFIG"):
        options.append(f"--config={os.environ['CONFIG']}")
    if os.environ.get("RUNNER"):
        options.append(f"--runner={os.environ['RUNNER']}")
    if os.environ.get("BUDGET"):
        options.append(f"--budget={os.environ['BUDGET']}")
    return options + report_options(os.environ.get("REPORTS", ""))


def _payload() -> dict:
    path = os.environ.get("GITHUB_EVENT_PATH", "")
    return _json_file(path) if path and os.path.isfile(path) else {}


def _json_file(path: str) -> dict:
    with open(path, encoding="utf-8") as file:
        loaded = json.load(file)
    return loaded if isinstance(loaded, dict) else {}


def _write(values: dict[str, str]) -> None:
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as file:
        file.write(output_lines(values))


if __name__ == "__main__":
    sys.exit(main(sys.argv))

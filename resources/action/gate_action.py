#!/usr/bin/env python3
"""What the action and the reusable workflow decide before and after the gate runs.

Usage:
  gate_action.py guard     refuses an event the gate never runs under
  gate_action.py install   how the gate is installed, `install`: from the project's
                           Composer packages, or the release's PHAR, whose `tag`
                           it names from the ref the action is pinned to
  gate_action.py phar      downloads that release's PHAR, or ASSET where it names
                           the judge's, verifies its signature and its
                           attestation, and names it as `gate`
  gate_action.py judge     what builds the run's judge, JUDGE (ADR-0140): the
                           `commit` to build it from, or `release`, the newest
                           release's judge, with its `tag`; where it refuses,
                           it names why as `refused` before it exits 2
  gate_action.py resolve   the run's scope, ledger cache, gate binary and options:
                           `plan_options` adds the mode's to `options`
  gate_action.py run-times writes what the jobs JOBS lists took to RUN_TIMES, for
                           the verdict to read; where it lists none it writes
                           nothing, and the verdict estimates
  gate_action.py config    from the effective config on stdin: whether it keeps
                           its ledger in the default directory, which copy of a
                           ledger it reads, whether deliver keeps the ledger in
                           the cache after the store, from STORE and ON_DEFAULT,
                           whether it runs Pest, and with the optional patches, and
                           whether it runs Infection
  gate_action.py merged-records  on a push to the default branch, into FETCHED, the
                           ledger each pull request merged into the last commits
                           left with its pass, read from its run's artifact, and
                           its head fetched; on a merge group, its pull
                           request's: `records`, how many it read; what it
                           cannot read costs a run
  gate_action.py infection what `infection:patch` exited with, CODE, and said,
                           in the file SAID: a warning where the release runs
                           unpatched, a refusal where it could not be patched
  gate_action.py outputs   the verdict, scores, report paths and files, and plan
  gate_action.py stale     from the run's jobs, JOBS, and the results, RESULTS:
                           removes each shard's result an earlier attempt wrote
                           than its job's latest run, saying so, and every
                           attempt file beside the results
  gate_action.py ended     from the run's jobs, JOBS, the error lines of their
                           logs, ERRORS, one file a job, and the results,
                           RESULTS: writes how each shard job that left no
                           result ended beside where its result would be
  gate_action.py keep      copies the ledger and the coverage map the verdict
                           left in FROM into OWN, the scope's directory the
                           Actions cache keeps, and names the `key` they are
                           saved under, PREFIX and their digest; none where
                           FROM holds neither

Every subcommand reads GitHub's environment and writes `key=value` lines to
$GITHUB_OUTPUT, or refuses with exit code 2 and says why; `stale` writes no
output, nor does `ended`.

Two halves: the functions below `main` read the environment and files, and
the ones above decide from what they read. The deciding half is what
.github/scripts/test_gate_action.py tests.
"""
from datetime import datetime, timezone
import hashlib
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile

# Each value below that the gate also holds is tied to the gate's own by
# tests/Arch/TheActionScriptAgreesWithTheGateTest.php.

# The events that run the default branch's workflow with a token or a cache
# that can write, over content a pull request supplies (ADR-0019). The gate
# never runs under them, so it never saves a ledger under them.
LOW_TRUST = frozenset({"pull_request_target", "issue_comment", "workflow_run"})

# The events whose ref is a branch a run may write the ledger of: none runs
# code from a pull request. The gate's own GitHub plan trusts the same three.
TRUSTED = frozenset({"push", "schedule", "workflow_dispatch"})

# Where a ref names a branch.
HEADS = "refs/heads/"

# The event a merge queue runs a group's checks on (ADR-0022).
MERGE_GROUP = "merge_group"

# The events `mode: auto` runs in full on (ADR-0005).
FULL = frozenset({"schedule", "workflow_dispatch", "release"})

MODES = frozenset({"auto", "full", "changed", "sampled", "unproven"})

LEDGERS = ".mutation-gate/ledger"

KEY = "mutation-gate-ledger-"

# The copy of a ledger proofs.copies chooses where a config names none, and the
# one whose cache copy the deliver job keeps after the store (ADR-0061).
NEWEST = "newest"
TOGETHER = "together"

# What the verdict leaves of a scope for the Actions cache to keep: its ledger
# and its kept coverage map, by the names the directory store reads.
KEPT = frozenset({"ledger.json.gz", "coverage.json.gz"})

GATE = "nightworksio/mutation-gate"

# The word the config names the Pest runner by, under `runner.use`; its
# options sit under the same word.
PEST = "pest"

# The word the config names the Infection runner by, under `runner.use`.
INFECTION = "infection"

# What Composer installed into a project, where the gate reads its own version.
INSTALLED = "vendor/composer/installed.json"

# The action's own release line, which the installed gate must share: its
# major, or its major and minor while the major is 0, as Composer's caret
# reads a version. The release pull request of a new line moves it
# (release.py).
LINE = "0.1"

# How the action may install the gate (ADR-0051): `auto` takes the project's
# Composer copy where it requires the gate, and the release's PHAR where not.
COMPOSER = "composer"
PHAR_INSTALL = "phar"
INSTALLS = frozenset({"auto", COMPOSER, PHAR_INSTALL})

# The repository each release is published from, whose release workflow signs
# and attests its PHAR (ADR-0022), and that workflow.
RELEASES = "nightworksio/php-mutation-gate"
RELEASE_WORKFLOW = f"{RELEASES}/.github/workflows/release.yml"

# The fingerprint of the key that signs each release's PHAR, as README.md and
# SECURITY.md name it, and the key itself, beside this script.
RELEASE_FINGERPRINT = "5EAA6082E57FF4C542A2EA66B757A4B1136116D2"
RELEASE_KEY_FILE = "release-key.asc"

# A release's PHAR and its detached signature, as the release names them.
PHAR = "mutation-gate.phar"
SIGNATURE = "mutation-gate.phar.asc"

# A release's judge PHAR and its detached signature (ADR-0140).
JUDGE_PHAR = "mutation-gate-judge.phar"
JUDGE_SIGNATURE = "mutation-gate-judge.phar.asc"

# What the reusable workflow's `judge` names besides a commit or tag of the gate's repository: the build at
# the commit the change branched from, or the newest release where none builds; or the newest release.
JUDGE_MERGE_BASE = "merge-base"
JUDGE_RELEASE = "release"

# The modes that build the gate from its own history, which only its repository holds.
JUDGE_OWN_HISTORY = (JUDGE_MERGE_BASE,)

# The script a commit of the gate holds where it builds a judge.
JUDGE_BUILD = "scripts/judge/scope.py"

# How the resolve step names a judge's PHAR as the gate that runs.
JUDGE_INSTALL = "judge"

# A release's tag, a release line's tag, and a commit, as a ref may name them.
RELEASE_TAG = re.compile(r"^v(\d+)\.(\d+)\.(\d+)$")
LINE_TAG = re.compile(r"^v\d+(?:\.\d+)?$")
COMMIT = re.compile(r"^[0-9a-f]{40}$")

# How `git ls-remote --tags` names a tag, and the commit an annotated one
# points at.
TAGS = "refs/tags/"
PEELED = "^{}"

EXIT_CODES = {0: "passed", 1: "failed", 2: "cannot-judge"}

# What `infection:patch` exits with where the installed Infection is a release
# it does not patch, which then runs with its own limit for each mutant.
UNSUPPORTED_RELEASE = 1
# The section of a shard's result that names the survivor it stopped on once
# its run could not pass (ADR-0008), and the fields it names it by.
MARK = "mark"
ACTION_REPORTS = {
    "json": "json:.mutation-gate/action/report.json",
    "html": "html:.mutation-gate/action/html",
    "digest": "digest:.mutation-gate/action/mutation-gate-digest.txt",
}
# The artifact a passing pull request's verdict leaves, and the file it holds:
# its scope's ledger as the verdict wrote it, with its pass (ADR-0139).
PASSED_ARTIFACT = "mutation-gate-passed"
PASSED_FILE = "passed.ledger.json.gz"
# The name a ledger file has, under its scope's directory.
LEDGER_FILE = "ledger.json.gz"
# The commits a run looks back over: as far as the gate's proving range reads.
PROVING_RANGE = 20
# The most a ledger the gate writes packs into: LedgerLimits::PACKED.
MOST_PASSED_BYTES = 11000000
# A merge queue's branch, which names the pull request a merge group was built for.
QUEUE_BRANCH = re.compile(r"^gh-readonly-queue/.+/pr-(\d+)-[0-9a-f]+$")

# A shard job's name as GitHub lists it, which holds the shard's number: the
# reusable workflow names it `shard <number> (<label>)`, after the calling
# job's name.
SHARD_JOB = re.compile(r"(?:^| / )shard (\d+) \(")

# The file beside a shard's result that holds the attempt whose job wrote it.
ATTEMPT = ".attempt"

# The file in a shard's result's place, where its job left none, that says how
# the job ended: the gate names it in the verdict (see `ended`).
ENDED = ".ended.json"

# How a shard job that left no result ended, as that file names it: its runner
# received a shutdown signal, lost contact with GitHub, the matrix or the run
# cancelled it, it ran past its time limit, or it failed.
SHUTDOWN = "shutdown"
LOST = "lost"
CANCELLED = "cancelled"
TIMED_OUT = "timed-out"
FAILED = "failed"

# The fields of that file: how the job ended, the job's address, and the shard
# whose failure the matrix cancelled it after.
HOW = "how"
JOB = "job"
AFTER = "after"

# What a job's log says, in a line GitHub marks as an error, where its runner
# received a shutdown signal and where the job ran past its time limit.
SHUTDOWN_SAID = "The runner has received a shutdown signal"
TIMED_OUT_SAID = "has exceeded the maximum execution time"


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
    if event in TRUSTED and ref.startswith(HEADS):
        return ref
    return None


def from_fork(payload: dict) -> bool:
    """Whether a pull request's head is in another repository than its base."""
    pull = payload.get("pull_request") or {}
    head = ((pull.get("head") or {}).get("repo") or {}).get("full_name")
    base = (payload.get("repository") or {}).get("full_name")
    return bool(pull) and head != base


def mode_arguments(
    mode: str, changed_since: str, event: str, ref: str, payload: dict, default_branch: str, merged_onto: str = ""
) -> list[str]:
    """The options that choose what the gate considers: every unit, or what changed since a ref.

    With no base given, a pull request runs from the commit its checkout
    merges it onto, `merged_onto`, the first parent of the merge GitHub makes
    for it, and from its base where the checkout is no merge. Its base's sha
    is the base branch where the pull request was last updated, so once that
    branch moves on, a diff from it would count all it gained since as the pull
    request's change. A merge group runs from the commit its queue built it on
    (ADR-0022), which holds every pull request the group merges. Another branch
    runs from the default branch, and the default branch from the last commit
    whose verdict passed (ADR-0005). `sampled` considers what `auto` does, and
    samples the units no proof covers (ADR-0020). `unproven` considers every
    unit and takes no proof, so every unit runs (ADR-0084).
    """
    if mode not in MODES:
        raise Refused(f"mode is {mode!r}; it is auto, full, changed, sampled or unproven.")
    if mode == "unproven":
        return ["--unproven"]
    if mode == "sampled":
        return [*mode_arguments("auto", changed_since, event, ref, payload, default_branch, merged_onto), "--sample"]
    tag = ref.startswith("refs/tags/")
    if mode == "full" or (mode == "auto" and (event in FULL or tag)):
        return ["--full"]
    if changed_since:
        return [f"--changed-since={changed_since}"]
    base = ((payload.get("pull_request") or {}).get("base") or {}).get("sha")
    if event == "pull_request" and merged_onto:
        return [f"--changed-since={merged_onto}"]
    if event == "pull_request" and isinstance(base, str) and base:
        return [f"--changed-since={base}"]
    group = (payload.get("merge_group") or {}).get("base_sha")
    if event == MERGE_GROUP and isinstance(group, str) and group:
        return [f"--changed-since={group}"]
    branch = ref.removeprefix(HEADS) if ref.startswith(HEADS) else ""
    if branch and default_branch and branch != default_branch:
        return [f"--changed-since=origin/{default_branch}"]
    return ["--changed-since=last-passed"]


def merged_onto(parents: list[str]) -> str:
    """The commit a merge checkout merges onto, its first parent, from `git rev-list --parents`: none where it is no merge."""
    return parents[1] if len(parents) > 2 else ""


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
    default = f"{HEADS}{default_branch}" if default_branch else ""
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


def passed_artifacts(listed: list[dict], repository_id: int) -> dict[str, int]:
    """The newest artifact of each commit, by its run's head, from the listing newest first: this repository's alone."""
    found: dict[str, int] = {}
    for artifact in listed:
        run = artifact.get("workflow_run") or {}
        head = run.get("head_sha", "")
        same_repository = run.get("head_repository_id") == repository_id
        if head and same_repository and not artifact.get("expired", True):
            found.setdefault(head, artifact["id"])
    return found


def merged_heads(pulls: list[dict], repository: str, default_branch: str) -> list[tuple[int, str]]:
    """Each pull request of this repository merged into its default branch, with its head."""
    return [
        (pull["number"], pull["head"]["sha"])
        for pull in pulls
        if pull.get("merged_at")
        and pull["base"]["ref"] == default_branch
        and (pull["head"].get("repo") or {}).get("full_name") == repository
    ]


def passed_ledger(archive: bytes) -> bytes | None:
    """The ledger an artifact's archive holds; none where it holds none, or one too large to be of one pass."""
    try:
        with zipfile.ZipFile(io.BytesIO(archive)) as opened:
            held = opened.getinfo(PASSED_FILE)
            return opened.read(held) if held.file_size <= MOST_PASSED_BYTES else None
    except (KeyError, zipfile.BadZipFile):
        return None


def kept_key(prefix: str, kept: dict[str, bytes]) -> str:
    """The cache key a scope's kept files are saved under: its prefix and their digest; none where none is kept."""
    if not kept:
        return ""
    digest = hashlib.sha256()
    for name in sorted(kept):
        digest.update(name.encode() + b"\0" + hashlib.sha256(kept[name]).digest())
    return prefix + digest.hexdigest()


def binary(root_manifest: dict) -> str:
    """The gate the project runs: its own `bin/` for the package itself, its vendor's otherwise."""
    return "bin/mutation-gate" if root_manifest.get("name") == GATE else "vendor/bin/mutation-gate"


def line_of(version: str) -> str:
    """A version's release line: its major, or its major and minor while the major is 0."""
    parts = version.lstrip("v").split(".")
    return parts[0] if parts[0] != "0" else ".".join(parts[:2])


def installed_version(installed: dict) -> str:
    """The gate's version as Composer installed it, from installed.json; nothing where it is not installed."""
    packages = installed.get("packages")
    for package in packages if isinstance(packages, list) else []:
        if isinstance(package, dict) and package.get("name") == GATE and isinstance(package.get("version"), str):
            return package["version"]
    return ""


def version_refusal(installed: str, line: str) -> str | None:
    """Why the installed gate is not of the action's release line, or nothing where it is."""
    if not installed:
        return f"The project does not install {GATE}: require {GATE} ^{line}."
    if installed.startswith("dev-") or installed.endswith("-dev"):
        return None
    if line_of(installed) != line:
        return (
            f"This action is mutation-gate {line}.x, and the project installs {installed}. "
            f"Pin the action to the tag of the installed version's line, or require {GATE} ^{line}."
        )
    return None


def requires_gate(manifest: dict) -> bool:
    """Whether a project's composer.json requires the gate, or is the gate."""
    required = {**(manifest.get("require") or {}), **(manifest.get("require-dev") or {})}
    return manifest.get("name") == GATE or GATE in required


def install(asked: str, manifest: dict) -> str:
    """How the gate is installed: `composer` or `phar`, as asked, or by whether the project requires it."""
    if asked not in INSTALLS:
        raise Refused(f"install is auto, composer or phar, not {asked}.")
    if asked != "auto":
        return asked
    return COMPOSER if requires_gate(manifest) else PHAR_INSTALL


def action_ref(ref: str, path: str) -> str:
    """The ref the action is pinned to: GitHub's, or the last part of the path GitHub fetched the action into."""
    return ref or path.rstrip("/").rsplit("/", 1)[-1]


def remote_tags(listed: str) -> dict[str, str]:
    """Each tag `git ls-remote --tags` lists, with the commit it points at."""
    tags = {}
    for line in listed.splitlines():
        commit, _, name = line.partition("\t")
        if name.startswith(TAGS):
            tag = name[len(TAGS):]
            peeled = tag.endswith(PEELED)
            tag = tag.removesuffix(PEELED)
            tags[tag] = commit if peeled or tag not in tags else tags[tag]
    return tags


def release_tag(ref: str, tags: dict[str, str]) -> str:
    """The release whose PHAR the action runs: the one its ref names, a release tag, a line's tag or a commit."""
    commit = ref if COMMIT.match(ref) else tags.get(ref) if RELEASE_TAG.match(ref) or LINE_TAG.match(ref) else None
    releases = [tag for tag, at in tags.items() if RELEASE_TAG.match(tag) and at == commit]
    if commit is None or not releases:
        raise Refused(
            f"The action runs the gate's PHAR from the release it is pinned to, and {ref or 'its ref'} is no release's "
            f"tag or commit. Pin it to a release, as {RELEASES}@<commit> # v{LINE}.<patch>, "
            f"or require {GATE} with Composer, and the action runs that copy."
        )
    return max(releases, key=lambda tag: tuple(int(part) for part in RELEASE_TAG.match(tag).groups()))


def signed_by(status: str, fingerprint: str) -> bool:
    """Whether gpg's status lines say a good signature, by the key with this fingerprint, that has not expired."""
    lines = [line.split() for line in status.splitlines()]
    good = any(fields[:2] == ["[GNUPG:]", "GOODSIG"] for fields in lines)
    return good and any(
        fields[:2] == ["[GNUPG:]", "VALIDSIG"] and fingerprint in fields[2:3] + fields[11:12] for fields in lines
    )


def run_times(jobs: list[dict], now: datetime) -> dict | None:
    """The wall and runner seconds of a run's jobs, a job still running counted up to now, and when the plan's job
    began and what it took; none of a run with no job that started. Runner seconds are each job's own, not what
    GitHub bills, which rounds each up to a minute."""
    spans = []
    plan = {}
    for job in jobs:
        started = _instant(job.get("started_at"))
        if started is None:
            continue
        end = _instant(job.get("completed_at")) or now
        spans.append((started, end))
        if job.get("name") == "plan" or str(job.get("name", "")).endswith(" / plan"):
            at = started.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
            plan = {"plan": {"at": at, "seconds": (end - started).total_seconds()}}
    if not spans:
        return None
    wall = max(end for _, end in spans) - min(start for start, _ in spans)
    runner = sum(((end - start).total_seconds() for start, end in spans), 0.0)
    return {"wallSeconds": wall.total_seconds(), "runnerSeconds": runner, **plan}


def _instant(text: object) -> datetime | None:
    """The instant an API timestamp names; none where it names none."""
    if not isinstance(text, str) or text == "":
        return None
    try:
        return datetime.fromisoformat(text)
    except ValueError:
        return None


def judge_candidates(judge: str, event: str, payload: dict, repository: str, default_branch: str) -> tuple[list[list[str]], bool]:
    """The commits a run's judge may be built from, the first that can build one taken, each as the revisions
    it is: one, or two whose merge base it is; and whether the newest release's judge is taken where none
    builds one.

    The change's own history is the gate's, so only its repository names `merge-base`. A change is a pull
    request's head from its base, a merge group's HEAD from its base, and any other run's HEAD from the default
    branch, whose merge base on the default branch is the commit itself. `merge-base` builds where it branched,
    then its base, then takes the newest release (ADR-0140).
    """
    if judge == JUDGE_RELEASE:
        return [], True
    if judge not in JUDGE_OWN_HISTORY:
        return [[judge]], False
    if repository != RELEASES:
        raise Refused(
            f"judge: {judge} builds the gate from its own history, so only {RELEASES} names it. "
            f"Name {JUDGE_RELEASE}, or a commit or tag of {RELEASES}."
        )
    pull_request = payload.get("pull_request") or {}
    group = payload.get(MERGE_GROUP) or {}
    base, head = {
        "pull_request": ((pull_request.get("base") or {}).get("sha", ""), (pull_request.get("head") or {}).get("sha", "")),
        MERGE_GROUP: (group.get("base_sha", ""), "HEAD"),
    }.get(event, (f"origin/{default_branch}", "HEAD"))
    return [[base, head], [base]], True


def phar_refusal(config: dict, installed_by: str) -> str | None:
    """Why the PHAR cannot run the effective config's runner, or nothing where it can (ADR-0022)."""
    if installed_by == PHAR_INSTALL and (config.get("runner") or {}).get("use") == PEST:
        return (
            "Pest runs the gate's plugin, which only the Composer package installs, so the PHAR cannot run it: "
            f"composer require --dev {GATE} -W, and the action runs that copy."
        )
    return None


def keeps_default_store(config: dict) -> bool:
    """Whether the effective config keeps its ledger in the directory the cache holds."""
    store = (config.get("proofs") or {}).get("store") or {}
    path = (store.get("with") or {}).get("path", LEDGERS)
    return store.get("use", "directory") == "directory" and path.rstrip("/") == LEDGERS


def copies(config: dict) -> str:
    """Which copy of a ledger the effective config reads where the store's and the cache's differ: `proofs.copies`."""
    return (config.get("proofs") or {}).get("copies") or NEWEST


def keeps_after_store(chosen: str, store: str, on_default_branch: bool) -> bool:
    """Whether the deliver job, not the verdict, keeps the scope's ledger in the cache, once it wrote the store's:
    for `together`, where a store is named, on the default branch, the one scope deliver writes the store of."""
    return chosen == TOGETHER and store != "" and on_default_branch


def runs_pest(config: dict) -> bool:
    """Whether the effective config runs Pest, by its `runner.use`."""
    return (config.get("runner") or {}).get("use") == PEST


def patches_pest(config: dict) -> bool:
    """Whether the effective config runs Pest with the optional patches on (ADR-0004)."""
    return runs_pest(config) and (config.get(PEST) or {}).get("patch") is True


def fail_fast(config: dict) -> str:
    """What a pull request's shard does once it cannot pass: `run.failFast`, `mark` where the config names none."""
    return (config.get("run") or {}).get("failFast") or MARK


def last_judges(config: dict) -> bool:
    """Whether the job that finishes the last item of a pulling plan computes the verdict: `shards.lastJudges`."""
    return (config.get("shards") or {}).get("lastJudges") is True


def cache_store(config: dict) -> str:
    """Where `outbreak cache` keeps its packs under the action: `outbreak.cache.store`, `auto` being `oci` where a
    registry is named and `actions` otherwise, and `none` where Outbreak is off (ADR-0131)."""
    outbreak = config.get("outbreak") or {}
    cache = outbreak.get("cache") or {}
    store = cache.get("store") or "auto"
    if outbreak.get("enabled") is False or store == "none":
        return "none"
    if store == "auto":
        return "oci" if cache.get("registry") else "actions"
    return store if store != "oci" or cache.get("registry") else "none"


def patches_infection(config: dict) -> bool:
    """Whether the effective config runs Infection, whose mutants get the gate's limit only once patched (ADR-0004)."""
    return (config.get("runner") or {}).get("use") == INFECTION


def infection_patched(code: int, said: str) -> str | None:
    """What the action says of `infection:patch`'s exit: nothing where it patched, a warning where the release
    runs unpatched, or a refusal where a release it supports could not be patched (ADR-0004)."""
    if code == 0:
        return None
    if code == UNSUPPORTED_RELEASE:
        return f"Infection runs unpatched, with its own limit for each mutant: {said}"
    raise Refused(said or f"infection:patch exited {code}.")


def shard_attempts(jobs: list) -> dict[str, int]:
    """The attempt of each shard job's latest run, by the shard's number, from the run's jobs as GitHub lists them.

    A re-run lists the jobs it kept under its own attempt, each created anew and started when its earlier
    attempt started, so a job that started before it was created ran in an earlier attempt and counts for none.
    """
    latest: dict[str, int] = {}
    for job in jobs:
        named = SHARD_JOB.search(str(job.get("name", ""))) if isinstance(job, dict) and not kept(job) else None
        attempt = job.get("run_attempt") if named else None
        if named and isinstance(attempt, int):
            latest[named.group(1)] = max(attempt, latest.get(named.group(1), 0))
    return latest


def kept(job: dict) -> bool:
    """Whether a re-run listed this job as kept from an earlier attempt: it started before it was created."""
    started, created = job.get("started_at"), job.get("created_at")
    return isinstance(started, str) and isinstance(created, str) and started < created


def stale(written: dict[str, int], latest: dict[str, int]) -> list[str]:
    """The shards whose result an earlier attempt wrote than their job's latest run: that run wrote none."""
    return sorted((shard for shard, attempt in written.items() if attempt < latest.get(shard, 0)), key=int)


def endings(jobs: list, errors: dict[int, str]) -> dict[str, dict]:
    """How each shard job's latest run ended, by the shard's number, where it did not pass or skip.

    `errors` holds the lines a job's log marks as errors, by the job's id. A job GitHub cancelled is named
    beside the first shard job of its attempt that failed before it ended, which the matrix's fail-fast
    cancels the others for; a failed job whose steps never all ended lost its runner.
    """
    latest = shard_attempts(jobs)
    runs = {
        number: job
        for job in jobs
        if isinstance(job, dict) and not kept(job)
        for number in [_shard_number(job)]
        if number is not None and job.get("run_attempt") == latest.get(number)
    }
    failed = sorted(
        (str(job.get("completed_at", "")), number)
        for number, job in runs.items()
        if job.get("conclusion") == "failure"
    )
    ended = {}
    for number, job in runs.items():
        how = ending(job, errors.get(job.get("id"), "") if isinstance(job.get("id"), int) else "")
        if how is None:
            continue
        ended[number] = {HOW: how} | ({JOB: job["html_url"]} if isinstance(job.get("html_url"), str) else {})
        if how == CANCELLED:
            before = [shard for at, shard in failed if shard != number and at <= str(job.get("completed_at", ""))]
            ended[number] |= {AFTER: int(before[0])} if before else {}
    return ended


def ending(job: dict, errors: str) -> str | None:
    """How a shard job ended, by its conclusion, its steps and its log's error lines; none where it passed or skipped."""
    conclusion = job.get("conclusion")
    steps = job.get("steps") if isinstance(job.get("steps"), list) else []
    if conclusion in (None, "success", "skipped", "neutral"):
        return None
    if SHUTDOWN_SAID in errors:
        return SHUTDOWN
    if TIMED_OUT_SAID in errors or conclusion == "timed_out":
        return TIMED_OUT
    if conclusion == "cancelled":
        return CANCELLED
    if any(isinstance(step, dict) and step.get("conclusion") is None for step in steps):
        return LOST
    return FAILED


def _shard_number(job: dict) -> str | None:
    named = SHARD_JOB.search(str(job.get("name", "")))
    return named.group(1) if named else None


def stale_text(shard: str, written: int, latest: int) -> str:
    """Why a shard's result is not judged."""
    return (
        f"Shard {shard} ran again in attempt {latest} and wrote no result, so the result attempt {written} "
        "wrote is not judged, and the verdict finds none for it."
    )


def command_text(text: str) -> str:
    """Text a workflow command carries whole: its percent signs and line breaks escaped."""
    return text.replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")


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
    commands = {
        "guard": _guard,
        "install": _install,
        "phar": _phar,
        "judge": _judge,
        "resolve": _resolve,
        "config": _config,
        "infection": _infection,
        "outputs": _outputs,
        "keep": _keep,
        "run-times": _run_times,
        "merged-records": _merged_records,
    }
    if len(argv) == 2 and argv[1] == "stale":
        return _stale()
    if len(argv) == 2 and argv[1] == "ended":
        return _ended()
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


def _install() -> dict[str, str]:
    _guard()
    installed_by = install(os.environ.get("INSTALL", "auto"), _json_file("composer.json"))
    if installed_by == COMPOSER:
        return {"install": COMPOSER}
    ref = action_ref(os.environ.get("GITHUB_ACTION_REF", ""), os.environ.get("GITHUB_ACTION_PATH", ""))
    listed = _ran(
        ["git", "ls-remote", "--tags", f"https://github.com/{RELEASES}"], f"The tags of {RELEASES} could not be listed"
    )
    return {"install": PHAR_INSTALL, "tag": release_tag(ref, remote_tags(listed))}


def _phar() -> dict[str, str]:
    tag = os.environ["TAG"]
    asset, signed = (JUDGE_PHAR, JUDGE_SIGNATURE) if os.environ.get("ASSET") == JUDGE_PHAR else (PHAR, SIGNATURE)
    directory = tempfile.mkdtemp(prefix="mutation-gate-", dir=os.environ["RUNNER_TEMP"])
    phar = os.path.join(directory, asset)
    signature = os.path.join(directory, signed)
    download = ["gh", "release", "download", tag, "--repo", RELEASES, "--dir", directory]
    _ran([*download, "--pattern", asset, "--pattern", signed], f"The PHAR of {tag} could not be downloaded")
    home = tempfile.mkdtemp(prefix="gnupg-", dir=os.environ["RUNNER_TEMP"])
    key = os.path.join(os.path.dirname(os.path.abspath(__file__)), RELEASE_KEY_FILE)
    _ran(["gpg", "--homedir", home, "--batch", "--quiet", "--import", key], "The release key could not be read")
    verified = subprocess.run(
        ["gpg", "--homedir", home, "--batch", "--status-fd", "1", "--verify", signature, phar],
        capture_output=True, text=True, check=False, timeout=60,
    )
    if not signed_by(verified.stdout, RELEASE_FINGERPRINT):
        raise Refused(f"The PHAR of {tag} is not signed by the release key {RELEASE_FINGERPRINT}, so it is not run.")
    attested = ["gh", "attestation", "verify", phar, "--repo", RELEASES, "--signer-workflow", RELEASE_WORKFLOW]
    unattested = f"The PHAR of {tag} carries no attestation of {RELEASE_WORKFLOW}, so it is not run"
    _ran([*attested, "--source-ref", f"{TAGS}{tag}"], unattested)
    os.chmod(phar, 0o755)
    return {"gate": phar}


def _run_times() -> dict[str, str]:
    jobs = _json_list(os.environ.get("JOBS", ""))
    times = run_times(jobs, datetime.now(timezone.utc))
    if times is None:
        print("::notice::The list of this run's jobs says nothing of what they took, so the verdict estimates it.", file=sys.stderr)
        return {}
    with open(os.environ.get("RUN_TIMES", ""), "w", encoding="utf-8") as file:
        json.dump(times, file)
    return {}


def _json_list(path: str) -> list[dict]:
    """The objects of a JSON list in a file; none where there is no such file."""
    if not os.path.isfile(path):
        return []
    with open(path, encoding="utf-8") as file:
        loaded = json.load(file)
    return [item for item in loaded if isinstance(item, dict)] if isinstance(loaded, list) else []


def _judge() -> dict[str, str]:
    try:
        return _judge_build()
    except Refused as refused:
        _write({"refused": str(refused)})
        raise


def _judge_build() -> dict[str, str]:
    judge = os.environ.get("JUDGE", "")
    candidates, release = judge_candidates(
        judge,
        os.environ.get("GITHUB_EVENT_NAME", ""),
        _payload(),
        os.environ.get("GITHUB_REPOSITORY", ""),
        os.environ.get("DEFAULT_BRANCH", ""),
    )
    if candidates == [[judge]] and os.environ.get("GITHUB_REPOSITORY", "") != RELEASES:
        _ran(
            ["git", "fetch", "--quiet", "--depth=1", f"https://github.com/{RELEASES}", judge],
            f"{judge} could not be fetched from {RELEASES}",
        )
        candidates = [["FETCH_HEAD"]]
    for revisions in candidates:
        commit = _commit(revisions)
        if commit and _holds(commit, JUDGE_BUILD):
            return {"commit": commit}
    if not release:
        raise Refused(f"{judge} builds no judge: it holds no {JUDGE_BUILD}, which builds one.")
    tag = _ran(
        ["gh", "release", "view", "--repo", RELEASES, "--json", "tagName", "--jq", ".tagName"],
        f"No release of {RELEASES} could be found to judge with",
    ).strip()
    return {"release": "true", "tag": tag}


def _resolve() -> dict[str, str]:
    _guard()
    event = os.environ.get("GITHUB_EVENT_NAME", "")
    ref = os.environ.get("GITHUB_REF", "")
    payload = _payload()
    manifest = _json_file("composer.json")
    phar = os.environ.get("INSTALLED_BY") in (PHAR_INSTALL, JUDGE_INSTALL)
    gate = os.environ.get("GATE_PHAR", "") if phar else binary(manifest)
    if os.environ.get("INSTALLED_BY") == JUDGE_INSTALL and os.path.isfile(gate):
        os.chmod(gate, 0o755)
    why = None if phar or gate.startswith("bin/") else version_refusal(installed_version(_installed()), LINE)
    if why is not None:
        raise Refused(why)
    default_branch = os.environ.get("DEFAULT_BRANCH", "")
    arguments = mode_arguments(
        os.environ.get("MODE", "auto"),
        os.environ.get("CHANGED_SINCE", ""),
        event,
        ref,
        payload,
        default_branch,
        merged_onto(_parents()) if event == "pull_request" else "",
    )
    options = _options()
    kept = ledgers(event, ref, payload, default_branch, os.environ.get("CACHE") == "true")
    return {"gate": gate, "options": json.dumps(options), "plan_options": json.dumps(options + arguments), **kept}


def action_reports(config: dict) -> list[str]:
    """Each --report the verdict step writes, as `outputs.actionReports` and `outputs.verdictOnly` say."""
    outputs = config.get("outputs", {})
    if outputs.get("verdictOnly") is True:
        return []
    return [f"--report={ACTION_REPORTS[name]}" for name in outputs.get("actionReports", ACTION_REPORTS)]


def _config() -> dict[str, str]:
    config = json.load(sys.stdin)
    refused = phar_refusal(config, os.environ.get("INSTALLED_BY", COMPOSER))
    if refused is not None:
        raise Refused(refused)
    kept = keeps_default_store(config)
    if not kept and os.environ.get("CACHE") == "true":
        print(f"::notice::The config keeps its ledger outside {LEDGERS}, so the Actions cache is not used.")
    return {
        "default_store": "true" if kept else "false",
        "copies": copies(config),
        "after_store": "true"
        if keeps_after_store(copies(config), os.environ.get("STORE", ""), os.environ.get("ON_DEFAULT") == "true")
        else "false",
        "pest": "true" if runs_pest(config) else "false",
        "pest_patch": "true" if patches_pest(config) else "false",
        "infection_patch": "true" if patches_infection(config) else "false",
        "fail_fast": fail_fast(config),
        "last_judges": "true" if last_judges(config) else "false",
        "cache_store": cache_store(config),
        "action_reports": json.dumps(action_reports(config)),
    }


def _infection() -> dict[str, str]:
    with open(os.environ["SAID"], encoding="utf-8") as file:
        said = file.read().strip()
    warning = infection_patched(int(os.environ["CODE"]), said)
    if warning is not None:
        print(f"::warning title=Infection runs unpatched::{command_text(warning)}")
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as file:
            file.write(f"> [!WARNING]\n> {warning}\n\n")
    return {}


def _stale() -> int:
    jobs = os.environ.get("JOBS", "")
    results = os.environ.get("RESULTS", "")
    with open(jobs, encoding="utf-8") as file:
        listed = json.load(file)
    latest = shard_attempts(listed if isinstance(listed, list) else [])
    written = {}
    for name in os.listdir(results) if os.path.isdir(results) else []:
        shard, extension = os.path.splitext(name)
        if extension == ".json":
            written[shard] = _attempt(os.path.join(results, shard + ATTEMPT))
    for shard in stale(written, latest):
        os.remove(os.path.join(results, shard + ".json"))
        print(f"::error title=Shard re-run wrote no result::{command_text(stale_text(shard, written[shard], latest[shard]))}")
    for name in os.listdir(results) if os.path.isdir(results) else []:
        if name.endswith(ATTEMPT):
            os.remove(os.path.join(results, name))
    return 0


def _ended() -> int:
    with open(os.environ["JOBS"], encoding="utf-8") as file:
        listed = json.load(file)
    results, logged = os.environ["RESULTS"], os.environ["ERRORS"]
    errors = {}
    for name in os.listdir(logged) if os.path.isdir(logged) else []:
        job, extension = os.path.splitext(name)
        if extension == ".txt" and job.isdigit():
            with open(os.path.join(logged, name), encoding="utf-8", errors="replace") as file:
                errors[int(job)] = file.read()
    os.makedirs(results, exist_ok=True)
    for shard, ended in endings(listed if isinstance(listed, list) else [], errors).items():
        if not os.path.isfile(os.path.join(results, shard + ".json")):
            with open(os.path.join(results, shard + ENDED), "w", encoding="utf-8") as file:
                json.dump(ended, file)
    return 0


def _keep() -> dict[str, str]:
    source, own = os.environ["FROM"], os.environ["OWN"]
    os.makedirs(own, exist_ok=True)
    kept = {}
    for name in KEPT:
        if os.path.isfile(os.path.join(source, name)):
            shutil.copyfile(os.path.join(source, name), os.path.join(own, name))
            with open(os.path.join(own, name), "rb") as file:
                kept[name] = file.read()
    return {"key": kept_key(os.environ["PREFIX"], kept)}


def _merged_records() -> dict[str, str]:
    repository, into = os.environ["GITHUB_REPOSITORY"], os.environ["FETCHED"]
    default_branch = os.environ["DEFAULT_BRANCH"]
    try:
        records = _read_records(repository, default_branch, into)
    except (Refused, subprocess.TimeoutExpired, ValueError, KeyError, TypeError) as unread:
        print(f"::warning::No pull request's pass was read, so this push is judged without any: {unread}")
        records = 0
    return {"records": str(records)}


def queued_pull_request(event: str, ref: str) -> int | None:
    """The pull request a merge group was built for, by its queue branch; none for any other run."""
    branch = ref.removeprefix("refs/heads/")
    queued = QUEUE_BRANCH.match(branch) if event == MERGE_GROUP else None
    return int(queued.group(1)) if queued else None


def _read_records(repository: str, default_branch: str, into: str) -> int:
    asked = f"repos/{repository}"
    identity = _api_json(asked)["id"]
    listed = _api_json(f"{asked}/actions/artifacts?name={PASSED_ARTIFACT}&per_page=100")["artifacts"]
    artifacts = passed_artifacts(listed, identity)
    queued = queued_pull_request(os.environ.get("GITHUB_EVENT_NAME", ""), os.environ.get("GITHUB_REF", ""))
    merged = (
        [(queued, _api_json(f"{asked}/pulls/{queued}")["head"]["sha"])]
        if queued is not None
        else _merged_since(asked, repository, default_branch)
    )
    records = 0
    for number, head in merged:
        if head in artifacts:
            records += _kept_record(asked, artifacts[head], number, into)
            _fetched(head)
    return records


def _merged_since(asked: str, repository: str, default_branch: str) -> list[tuple[int, str]]:
    commits = _api_json(f"{asked}/commits?sha={os.environ['GITHUB_SHA']}&per_page={PROVING_RANGE}")
    merged: list[tuple[int, str]] = []
    for commit in commits:
        merged += merged_heads(_api_json(f"{asked}/commits/{commit['sha']}/pulls"), repository, default_branch)
    return merged


def _fetched(head: str) -> None:
    """A pull request's judged head, fetched so a delta from it can be read; nothing where it cannot be."""
    subprocess.run(
        ["git", "fetch", "--no-tags", "--depth=1", "origin", head], capture_output=True, check=False, timeout=120
    )


def _kept_record(asked: str, artifact: int, number: int, into: str) -> int:
    fetched = subprocess.run(
        ["gh", "api", f"{asked}/actions/artifacts/{artifact}/zip"], capture_output=True, check=False, timeout=120
    )
    ledger = passed_ledger(fetched.stdout) if fetched.returncode == 0 else None
    if ledger is None:
        return 0
    directory = f"{into}/refs/pull/{number}"
    os.makedirs(directory, exist_ok=True)
    with open(f"{directory}/{LEDGER_FILE}", "wb") as file:
        file.write(ledger)
    return 1


def _api_json(path: str) -> dict | list:
    return json.loads(_ran(["gh", "api", path], "GitHub did not answer"))


def _attempt(path: str) -> int:
    """The attempt a result's attempt file holds; none, 0, where there is no file or it holds no number."""
    if not os.path.isfile(path):
        return 0
    with open(path, encoding="utf-8") as file:
        held = file.read().strip()
    return int(held) if held.isdigit() else 0


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


def _ran(command: list[str], failed: str) -> str:
    """What a command printed; refused, with what it said, where it failed."""
    ran = subprocess.run(command, capture_output=True, text=True, check=False, timeout=300)
    if ran.returncode != 0:
        raise Refused(f"{failed}: {command_text(ran.stderr.strip() or ran.stdout.strip())}")
    return ran.stdout


def _payload() -> dict:
    path = os.environ.get("GITHUB_EVENT_PATH", "")
    return _json_file(path) if path and os.path.isfile(path) else {}


def _parents() -> list[str]:
    """The checkout's commit and its parents, as git lists them: none where git cannot say, as in a shallow clone."""
    listed = subprocess.run(
        ["git", "rev-list", "--parents", "-n", "1", "HEAD"], capture_output=True, text=True, check=False, timeout=60
    )
    return listed.stdout.split() if listed.returncode == 0 else []


def _commit(revisions: list[str]) -> str:
    """The commit one revision names, or the merge base of two; none where git cannot say, as in a shallow clone."""
    command = ["git", "merge-base", *revisions] if len(revisions) > 1 else ["git", "rev-parse", "--verify", "--quiet", f"{revisions[0]}^{{commit}}"]
    named = subprocess.run(command, capture_output=True, text=True, check=False, timeout=60)
    return named.stdout.strip() if named.returncode == 0 else ""


def _holds(commit: str, path: str) -> bool:
    """Whether a commit holds a file at a path."""
    held = subprocess.run(["git", "cat-file", "-e", f"{commit}:{path}"], capture_output=True, check=False, timeout=60)
    return held.returncode == 0


def _installed() -> dict:
    return _json_file(INSTALLED) if os.path.isfile(INSTALLED) else {}


def _json_file(path: str) -> dict:
    with open(path, encoding="utf-8") as file:
        loaded = json.load(file)
    return loaded if isinstance(loaded, dict) else {}


def _write(values: dict[str, str]) -> None:
    with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as file:
        file.write(output_lines(values))


if __name__ == "__main__":
    sys.exit(main(sys.argv))

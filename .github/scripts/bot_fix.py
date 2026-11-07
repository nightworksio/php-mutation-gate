"""`/fix`: the fix a pull request's CI computed, committed to its branch by the App (ADR-0019).

The App writes only to the pull request's own branch: a branch of this
repository that is neither its base nor the default branch, and that no
branch protection or ruleset rule of main's config guards. It takes the fix
only from the pull request's own CI run: one on that branch, at the head it
read, that GitHub lists for this pull request.

The bot runs no tool. The bytes are the pull request's own CI's, read from
its `evidence` artifact, and committed only to that pull request's branch,
where its author could push them anyway. Before anything is committed:

- every file the fix changes is one the pull request changes, or one main's
  config lets a gate's fix reach beyond the diff (composer.json, for
  normalize), and none is under a path the bot never writes (workflows);
- the whole fix is within main's size cap;
- every hunk matches the head's text where it applies.

The App makes one commit through createCommitOnBranch with no custom author,
naming the head it read, so GitHub signs it, refuses it where the branch
moved, and starts CI on it.

Two halves: the functions above `commit` decide from values alone, and
`commit` asks GitHub.
"""
import base64

import bot_patch
import github_api

COMMIT = (
    "mutation($input: CreateCommitOnBranchInput!) "
    "{ createCommitOnBranch(input: $input) { commit { oid url } } }"
)


def off_limits(ref: str, base: str, default: str, protected: bool, rules: list[dict], protecting: list[str]) -> bool:
    """Whether a pull request's head branch is one the App must not write: its base, the default branch, or a guarded one."""
    guarded = any(isinstance(rule, dict) and rule.get("type") in protecting for rule in rules)
    return ref in (base, default) or protected or guarded


def own_run(run: dict, ref: str, sha: str, number: int) -> bool:
    """Whether a CI run is the pull request's own: on its branch, at its head, and listed for it."""
    listed = [item.get("number") for item in run.get("pull_requests") or [] if isinstance(item, dict)]
    return run.get("head_branch") == ref and run.get("head_sha") == sha and number in listed


def refusal(fixes: dict[str, list[bot_patch.FilePatch]], changed: set[str], config: dict, size: int) -> str:
    """Why the fixes cannot be committed, as a key of the reasons, or ''."""
    if size > config["most_patch_bytes"]:
        return "why-too-large"
    for gate, patches in fixes.items():
        for patch in patches:
            if patch.path.startswith(tuple(config["never"])):
                return "why-never"
            if patch.path not in changed and patch.path not in config["beyond_the_diff"].get(gate, []):
                return "why-not-in-diff"
    return ""


def applied(fixes: dict[str, list[bot_patch.FilePatch]], heads: dict[str, str | None]) -> dict[str, str]:
    """Each file's text with its fixes applied, in gate order; refused where a hunk does not match."""
    texts: dict[str, str] = {}
    for gate in sorted(fixes):
        for patch in fixes[gate]:
            current = texts.get(patch.path, heads.get(patch.path))
            if current is None:
                raise bot_patch.Refused(f"{patch.path}: the head has no text the bot reads")
            texts[patch.path] = bot_patch.apply(patch, current)
    return {path: text for path, text in texts.items() if text != heads.get(path)}


def commit(branch: str, head: str, texts: dict[str, str], headline: str, body: str) -> dict:
    """One signed commit of the texts on the branch, at the head read; GitHub refuses it where the branch moved."""
    data = github_api.graphql(COMMIT, {"input": {
        "branch": {"repositoryNameWithOwner": github_api.repository(), "branchName": branch},
        "message": {"headline": headline, "body": body},
        "expectedHeadOid": head,
        "fileChanges": {"additions": [
            {"path": path, "contents": base64.b64encode(text.encode("utf-8")).decode("ascii")} for path, text in sorted(texts.items())
        ]},
    }}, token="APP_TOKEN")
    return data["createCommitOnBranch"]["commit"]

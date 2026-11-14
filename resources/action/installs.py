"""How the action installs the gate: from the project's Composer packages, or from a release's signed and attested
PHAR, the release the action's ref names, or from a judge build (ADR-0051, ADR-0140). gate_action.py reads git,
gh and gpg and writes what these decide.
"""
import re

from refused import Refused

GATE = "nightworksio/mutation-gate"

# What Composer installed into a project, where the gate reads its own version.
INSTALLED = "vendor/composer/installed.json"

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


def release_tag(ref: str, tags: dict[str, str], line: str) -> str:
    """The release whose PHAR the action runs: the one its ref names, a release tag, a line's tag or a commit, which
    the refusal names as a release of the action's line."""
    commit = ref if COMMIT.match(ref) else tags.get(ref) if RELEASE_TAG.match(ref) or LINE_TAG.match(ref) else None
    releases = [tag for tag, at in tags.items() if RELEASE_TAG.match(tag) and at == commit]
    if commit is None or not releases:
        raise Refused(
            f"The action runs the gate's PHAR from the release it is pinned to, and {ref or 'its ref'} is no release's "
            f"tag or commit. Pin it to a release, as {RELEASES}@<commit> # v{line}.<patch>, "
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

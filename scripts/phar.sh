#!/usr/bin/env bash
# Usage: composer phar
#
# The gate's PHAR, built into build/mutation-gate.phar as a release builds it
# (ADR-0022): Box, at the release pinned here and run only once it matches
# its pinned digest, compiles box.json.dist with every file timed at the
# commit's own time and in one order, so one commit builds one archive. The
# archive's version is the one Composer gives the checkout: the tag the
# commit is at, or its branch.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
box_version=4.7.0
box_digest=3d390eeaec33288098fe83f8a54c60cc575cb6be295f38ff4482b4b4f26f8d52
box="${root}/build/box-${box_version}.phar"
config="${root}/build/box.json"
mkdir -p "${root}/build"
if [ ! -f "${box}" ]; then
  curl --fail --silent --show-error --location --retry 3 --output "${box}.part" \
    "https://github.com/box-project/box/releases/download/${box_version}/box.phar"
  mv "${box}.part" "${box}"
fi
if command -v sha256sum >/dev/null; then
  digest=$(sha256sum "${box}" | cut -d' ' -f1)
else
  digest=$(shasum -a 256 "${box}" | cut -d' ' -f1)
fi
if [ "${digest}" != "${box_digest}" ]; then
  rm -f "${box}"
  echo "Box ${box_version} does not match its pinned digest, so it is not run: ${digest}" >&2
  exit 1
fi
BOX_TIMESTAMP=$(git -C "${root}" log -1 --format=%cI) BOX_ROOT="${root}" php -r '
  $config = json_decode((string) file_get_contents(getenv("BOX_ROOT") . "/box.json.dist"), true, flags: JSON_THROW_ON_ERROR);
  $config["base-path"] = getenv("BOX_ROOT");
  $config["timestamp"] = getenv("BOX_TIMESTAMP");
  echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
' > "${config}"
php "${box}" compile --no-interaction --sort-compiled-files --config "${config}"

#!/usr/bin/env bash
# Usage: bench/fetch.sh <project file> <directory>
#
# The project at the commit its file pins, into the directory, refused unless
# the archive of its whole tree has the SHA-256 the file pins: git archive
# with every export-ignore and export-subst switched off, since a project's
# .gitattributes may leave its tests out of every archive (ADR-0017,
# decision 14).
set -euo pipefail
project=$1
directory=$2
repository=$(jq -r .repository "${project}")
commit=$(jq -r .commit "${project}")
tree=$(jq -r .tree "${project}")
source="${directory}.git-source"
git init --quiet "${source}"
git -C "${source}" fetch --quiet --depth 1 "${repository}" "${commit}"
git -C "${source}" checkout --quiet FETCH_HEAD
test "$(git -C "${source}" rev-parse HEAD)" = "${commit}"
# A repository's info/attributes is read before its tree's own.
printf '* -export-ignore -export-subst\n' > "${source}/.git/info/attributes"
git -C "${source}" archive --format=tar HEAD > "${directory}.tar"
sha256sum --check --strict <<<"${tree}  ${directory}.tar"
mkdir -p "${directory}"
tar xf "${directory}.tar" -C "${directory}"
rm -rf "${source}" "${directory}.tar"

#!/usr/bin/env bash
# The badge and the trend the verdict left in publish/, pushed to the branch
# PUBLISHED names through the contents API with the job's own token, so GitHub
# signs each commit (ADR-0009). The first publish makes the branch: an orphan
# commit that holds the files and nothing else. Every later one puts each
# changed file. Reads GH_TOKEN, GITHUB_REPOSITORY, PUBLISHED, MESSAGE and
# RUNNER_TEMP.
set -euo pipefail
shopt -s nullglob
files=(publish/*)
if [ "${#files[@]}" -eq 0 ]; then
  echo "The verdict wrote nothing to publish."
  exit 0
fi
repository="repos/${GITHUB_REPOSITORY}"
if ! gh api "${repository}/branches/${PUBLISHED}" --silent 2>/dev/null; then
  for path in "${files[@]}"; do
    jq -n --arg path "$(basename "${path}")" --rawfile content "${path}" \
      '{path: $path, mode: "100644", type: "blob", content: $content}'
  done | jq -s '{tree: .}' > "${RUNNER_TEMP}/tree.json"
  tree="$(gh api "${repository}/git/trees" --input "${RUNNER_TEMP}/tree.json" --jq .sha)"
  commit="$(jq -n --arg tree "${tree}" --arg message "${MESSAGE}" '{message: $message, tree: $tree, parents: []}' \
    | gh api "${repository}/git/commits" --input - --jq .sha)"
  gh api "${repository}/git/refs" -f ref="refs/heads/${PUBLISHED}" -f sha="${commit}" --silent
  exit 0
fi
for path in "${files[@]}"; do
  file="$(basename "${path}")"
  current="$(gh api "${repository}/contents/${file}?ref=${PUBLISHED}" --jq .sha 2>/dev/null || true)"
  if [ "${current}" = "$(git hash-object "${path}")" ]; then
    continue
  fi
  jq -n --arg message "${MESSAGE}" --arg branch "${PUBLISHED}" --arg sha "${current}" --rawfile content "${path}" \
    '{message: $message, branch: $branch, content: ($content | @base64)}
    + (if $sha == "" then {} else {sha: $sha} end)' \
    | gh api --method PUT "${repository}/contents/${file}" --input - --silent
done

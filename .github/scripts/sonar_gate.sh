#!/usr/bin/env bash
# Zero SonarCloud issues: none new on a pull request, and none open against the
# project. SonarCloud's free quality gate cannot say "zero", so this asks the
# API for both counts once the scan has been processed.
#
# Reads from the environment: SONAR_TOKEN; SCAN and TESTS, the results of the
# `sonar` and `tests` jobs; EVENT; and on a pull request PR, AUTHOR and FORK.
set -euo pipefail

project=$(sed -n 's/^sonar\.projectKey=//p' sonar-project.properties)
host=https://sonarcloud.io

say() {
	echo "$2"
	printf '### SonarCloud gate\n\n%s\n' "${2#::*::}" >>"${GITHUB_STEP_SUMMARY:-/dev/null}"
	exit "$1"
}

if [ "${TESTS}" = skipped ]; then
	say 0 "Nothing but documentation changed, so there is no analysis to gate."
fi

if [ "${TESTS}" != success ]; then
	say 1 "::error::The tests did not pass, so SonarCloud analysed nothing and this gate has nothing to read. Fix tests and coverage first."
fi

if [ "${SCAN}" = skipped ] && { [ "${FORK:-false}" = true ] || [ "${AUTHOR:-}" = "dependabot[bot]" ]; }; then
	say 0 "::notice::No SONAR_TOKEN reaches a pull request from ${AUTHOR}, so nothing was scanned and zero issues was not enforced on this run."
fi

if [ "${SCAN}" != success ]; then
	say 1 "::error::The SonarCloud scan did not pass (${SCAN}), so there are no counts to read. Open the sonarcloud job."
fi

# Sets `total` to the unresolved issues of the project, narrowed by $1.
count() {
	local status
	status=$(curl -sS --max-time 30 -o answer.json -w '%{http_code}' -H "Authorization: Bearer ${SONAR_TOKEN}" \
		"${host}/api/issues/search?projects=${project}&resolved=false&ps=1$1" || echo 000)
	if [ "${status}" != 200 ] || ! total=$(jq -er '.total' answer.json); then
		say 1 "::error::SonarCloud answered HTTP ${status} when asked for the issues of ${project}$1, so zero issues cannot be shown."
	fi
}

# A key that resolves to no project answers every count with zero.
found=$(curl -sS --max-time 30 -o /dev/null -w '%{http_code}' -H "Authorization: Bearer ${SONAR_TOKEN}" \
	"${host}/api/components/show?component=${project}" || echo 000)
if [ "${found}" != 200 ]; then
	say 1 "::error::No SonarCloud project called ${project} could be read (HTTP ${found}). Check sonar.projectKey in sonar-project.properties and the SONAR_TOKEN secret."
fi

faults=""

if [ "${EVENT}" = pull_request ]; then
	count "&pullRequest=${PR}"
	if [ "${total}" -ne 0 ]; then
		faults="${faults}${total} new issue(s) on this pull request: ${host}/project/issues?id=${project}&pullRequest=${PR}&resolved=false. "
	fi
fi

count ""
if [ "${total}" -ne 0 ]; then
	faults="${faults}${total} open issue(s) against ${project}: ${host}/project/issues?id=${project}&resolved=false. "
fi

if [ -n "${faults}" ]; then
	say 1 "::error::${faults}This repository allows none. Fix each, or resolve one in SonarCloud with the reason it is not a defect."
fi

say 0 "SonarCloud reports no new issue and no open issue against ${project}."

#!/usr/bin/env bash
# The service containers a job's tests reach, started before any of the project's code runs and waited for once
# it is about to need them (ADR-0189). Each takes the directory, under RUNNER_TEMP, that holds what outlives start.
#
#   services.sh start <dir>  under SERVICES_START `background`, starts each service of SERVICES, a JSON object as
#                            `jobs.<job_id>.services` takes, every one at once: its password and env values masked,
#                            a private image pulled under a Docker config of its own removed at once, each container
#                            started detached; leaves in <dir>/services only the containers' names, read-only, and
#                            nothing of SERVICES runs on once it ends. Under `job` starts none; refuses any other
#   services.sh wait <dir>   waits until each container <dir>/services names reports healthy, or runs where it has
#                            no health check; fails, with its log, where one exits, turns unhealthy or outlasts
#                            MUTATION_GATE_SERVICES_WAIT seconds (300 unless set), and where start left no list
#   services.sh stop <dir>   removes each container <dir>/services names, and its volumes
set -euo pipefail

docker="${DOCKER:-/usr/bin/docker}"
listed="${2:?the directory under RUNNER_TEMP that start leaves its list in}/services"

start() {
  case "${SERVICES_START:-background}" in
    background) ;;
    job) return ;;
    *)
      echo "::error::services-start is ${SERVICES_START}: background or job."
      exit 2
      ;;
  esac
  local all="${SERVICES:-}" directory name spec image container item names=()
  directory="$(dirname "${listed}")"
  if [ -z "${all}" ]; then
    all='{}'
  fi
  mkdir -p "${directory}"
  rm -f "${listed}" "${directory}/failed"
  while IFS= read -r name; do
    spec="$(jq -c --arg name "${name}" '.[$name]' <<< "${all}")"
    image="$(jq -r '.image // ""' <<< "${spec}")"
    if [ -z "${image}" ]; then
      continue
    fi
    masked "${spec}"
    if [ -n "$(jq -r '.credentials.username // ""' <<< "${spec}")" ]; then
      pulled "${image}" "${spec}"
    fi
    container="mutation-gate-${name}-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-0}-${GITHUB_JOB:-job}"
    local arguments=(--detach --name "${container}")
    while IFS= read -r item; do arguments+=(--env "${item}"); done < <(jq -r '.env // {} | to_entries[] | "\(.key)=\(.value)"' <<< "${spec}")
    while IFS= read -r item; do arguments+=(--publish "${item}"); done < <(jq -r '.ports // [] | .[] | tostring' <<< "${spec}")
    while IFS= read -r item; do arguments+=(--volume "${item}"); done < <(jq -r '.volumes // [] | .[]' <<< "${spec}")
    while IFS= read -r item; do
      if [ -n "${item}" ]; then arguments+=("${item}"); fi
    done < <(jq -r '.options // ""' <<< "${spec}" | xargs -n 1 printf '%s\n')
    "${docker}" rm --force --volumes "${container}" > /dev/null 2>&1 || true
    ("${docker}" run "${arguments[@]}" "${image}" > /dev/null 2> "${directory}/${container}.log" || echo "${container}" >> "${directory}/failed") &
    names+=("${container}")
  done < <(jq -r 'keys[]' <<< "${all}")
  wait
  if [ -s "${directory}/failed" ]; then
    while IFS= read -r container; do
      echo "::error::The service container ${container} did not start."
      cat "${directory}/${container}.log" || true
    done < "${directory}/failed"
    exit 1
  fi
  : > "${listed}"
  if [ "${#names[@]}" -gt 0 ]; then
    printf '%s\n' "${names[@]}" > "${listed}"
  fi
  chmod 0444 "${listed}"
}

masked() {
  local spec="$1" secret
  while IFS= read -r secret; do
    if [ "${#secret}" -ge 4 ]; then
      echo "::add-mask::${secret}"
    fi
  done < <(jq -r '[.credentials.password // empty, (.env // {} | .[] | tostring)] | .[]' <<< "${spec}")
}

pulled() {
  local image="$1" spec="$2" user registry config code=0
  user="$(jq -r '.credentials.username' <<< "${spec}")"
  registry="${image%%/*}"
  case "${registry}" in
    *.* | *:* | localhost) ;;
    *) registry="" ;;
  esac
  config="$(mktemp -d -p "${RUNNER_TEMP:-/tmp}" mutation-gate-docker-XXXXXX)"
  jq -j '.credentials.password // ""' <<< "${spec}" \
    | "${docker}" --config "${config}" login ${registry:+"${registry}"} --username "${user}" --password-stdin > /dev/null || code=$?
  if [ "${code}" -eq 0 ]; then
    "${docker}" --config "${config}" pull "${image}" > /dev/null || code=$?
  fi
  rm -rf "${config}"
  if [ "${code}" -ne 0 ]; then
    echo "::error::The service image ${image} could not be pulled with its credentials."
    exit 1
  fi
}

wait_for() {
  if [ ! -f "${listed}" ]; then
    echo "::error::No list of the service containers was left by the step that starts them."
    exit 1
  fi
  local most="${MUTATION_GATE_SERVICES_WAIT:-300}" started="${SECONDS}" container state
  while IFS= read -r container; do
    if [ -z "${container}" ]; then
      continue
    fi
    while true; do
      if ! state="$("${docker}" inspect --format '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "${container}" 2> /dev/null)"; then
        state='missing none'
      fi
      case "${state}" in
        "running healthy" | "running none") break ;;
        exited* | dead* | missing* | *unhealthy)
          echo "::error::The service container ${container} is ${state}."
          "${docker}" logs "${container}" 2>&1 | tail -n 50 || true
          exit 1
          ;;
      esac
      if [ $((SECONDS - started)) -ge "${most}" ]; then
        echo "::error::The service container ${container} was not healthy within ${most} s (${state})."
        "${docker}" logs "${container}" 2>&1 | tail -n 50 || true
        exit 1
      fi
      sleep 1
    done
    echo "${container}: ${state}"
  done < "${listed}"
}

stop() {
  local container
  if [ ! -f "${listed}" ]; then
    return
  fi
  while IFS= read -r container; do
    if [ -n "${container}" ]; then
      "${docker}" rm --force --volumes "${container}" > /dev/null 2>&1 || true
    fi
  done < "${listed}"
}

case "${1:-}" in
  start) start ;;
  wait) wait_for ;;
  stop) stop ;;
  *)
    echo "usage: services.sh start|wait|stop <dir>" >&2
    exit 2
    ;;
esac

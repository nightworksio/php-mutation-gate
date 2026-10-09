#!/usr/bin/env bash
# Prepares lcobucci/jwt, in its directory, for bench/jwt.json's arms: the
# gate from this checkout, and the PHPUnit and Infection its lock holds, so
# both arms that mutate with Infection run one release of it.
set -euo pipefail
source "${GITHUB_WORKSPACE}/bench/gate.sh"
jq --arg gate "${GITHUB_WORKSPACE}" --arg phpunit "$(bench_locked phpunit/phpunit)" \
  --arg infection "$(bench_locked infection/infection)" '
  .["require-dev"] = {
    "lcobucci/clock": .["require-dev"]["lcobucci/clock"],
    "phpunit/phpunit": $phpunit,
    "infection/infection": $infection,
    "nightworksio/mutation-gate": "@dev"
  }
  | del(.config.platform)
  | .config["allow-plugins"] = {"infection/extension-installer": false}
  | .repositories = [{"type": "path", "url": $gate, "options": {"symlink": true}}]
  | .["minimum-stability"] = "dev"
  | .["prefer-stable"] = true' composer.json > composer.json.new
mv composer.json.new composer.json
composer update --no-interaction --no-progress
composer show | grep -E '^(phpunit/phpunit|infection/infection|nightworksio/mutation-gate) '
# jwt's own PHPUnit is 11; PHPUnit 13 warns of its repeated `#[Uses]`
# attributes and refuses `beStrictAboutTodoAnnotatedTests`.
sed -e 's/failOnRisky="true"/failOnRisky="true" failOnPhpunitWarning="false" failOnPhpunitDeprecation="false"/' \
  -e 's/ *beStrictAboutTodoAnnotatedTests="true"//' phpunit.xml.dist > phpunit.xml
# SignedWithUntilDateTest names InMemory twice in #[UsesClass], which PHPUnit
# 12 and 13 warn of. Infection's initial run stops at the first defect, in a
# random order, so the warning cut that run short at a different test each
# time and Infection mutated whatever it had covered by then. The copy keeps
# each attribute once, which changes no test.
for test in $(grep -rl 'UsesClass(' tests); do
  awk '!(/^#\[PHPUnit\\UsesClass\(/ && seen[$0]++)' "${test}" > "${test}.once"
  mv "${test}.once" "${test}"
done
printf 'vendor/\n.mutation-gate/\nbench-*.json\ninfection.txt\n' > .gitignore
bench_baselines src
# jwt's infection.json.dist ignores mutants by method with no reason, which
# the gate refuses unless the config allows the runner's own ignores.
printf '{"runner": {"use": "infection"}, "trees": [{"path": "src"}], "ignores": {"native": "allow"}}\n' \
  > bench-infection.json
printf '{"runner": {"use": "phpunit", "workers": "fork"}, "trees": [{"path": "src"}]}\n' > bench-phpunit.json
# Plain Infection writes its JSON log only where its config names one.
jq '.logs.json = "bench-infection-log.json"' infection.json.dist > bench-plain-infection.json
bench_commit "lcobucci/jwt"
bench_plain_copy
vendor/bin/mutation-gate infection:patch

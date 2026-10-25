#!/usr/bin/env bash
# Prepares symfony/demo, in its directory, for bench/symfony-demo.json's
# arms: the gate from this checkout, and the PHPUnit and Infection this
# checkout's lock holds in place of its PHPUnit 11, so both arms that mutate
# with Infection run one release of it. The demo runs no mutation testing,
# so its Infection config is written here: src, with Infection's default
# mutators.
set -euo pipefail
source "${GITHUB_WORKSPACE}/bench/gate.sh"
repository=$(jq -cn --arg url "${GITHUB_WORKSPACE}" '{type: "path", url: $url, options: {symlink: false}}')
composer config --unset platform.php
composer config repositories.gate "${repository}"
composer config minimum-stability dev
composer config prefer-stable true
composer config allow-plugins.infection/extension-installer false
composer require --dev --no-interaction --no-progress --no-update \
  "phpunit/phpunit:$(bench_locked phpunit/phpunit)" "infection/infection:$(bench_locked infection/infection)" \
  'nightworksio/mutation-gate:@dev'
composer update --no-interaction --no-progress --with-all-dependencies
bench_versions phpunit/phpunit infection/infection nightworksio/mutation-gate
printf '.mutation-gate/\nbench-*.json\nbench-*.txt\ninfection.txt\ndata/database_test?*.sqlite\n' >> .gitignore
# The suite writes to one SQLite file, and processes that share it lock each
# other out, which fails tests a mutant never touched. Each process runs on a
# copy of its own, by the TEST_TOKEN Infection and the gate give each of
# their workers, as a parallel run of any suite on one database needs.
printf 'DATABASE_URL="sqlite:///%%kernel.project_dir%%/data/database_test${TEST_TOKEN:-}.sqlite"\n' > .env.test.local
for token in $(seq 0 64); do
  cp data/database_test.sqlite "data/database_test${token}.sqlite"
done
bench_baselines src
printf '{"source": {"directories": ["src"]}, "mutators": {"@default": true}}\n' > infection.json
printf '{"runner": {"use": "infection"}, "trees": [{"path": "src"}]}\n' > bench-infection.json
printf '{"runner": {"use": "phpunit", "workers": "fork"}, "trees": [{"path": "src"}]}\n' > bench-phpunit.json
# Plain Infection writes its JSON log only where its config names one.
jq '.logs.json = "bench-infection-log.json"' infection.json > bench-plain-infection.json
bench_commit "symfony/demo"
bench_plain_copy
vendor/bin/mutation-gate infection:patch

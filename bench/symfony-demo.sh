#!/usr/bin/env bash
# Prepares symfony/demo, in its directory, for bench/symfony-demo.json's
# arms: the gate from this checkout, and the PHPUnit and Infection this
# checkout's lock holds in place of its PHPUnit 11, so both arms that mutate
# with Infection run one release of it. The demo runs no mutation testing,
# so its Infection config is written here: src, with Infection's default
# mutators.
set -euo pipefail
source "${GITHUB_WORKSPACE}/bench/gate.sh"
repository=$(jq -cn --arg url "${GITHUB_WORKSPACE}" '{type: "path", url: $url, options: {symlink: true}}')
composer config --unset platform.php
composer config repositories.gate "${repository}"
composer config minimum-stability dev
composer config prefer-stable true
composer config allow-plugins.infection/extension-installer false
composer require --dev --no-interaction --no-progress --no-update \
  "phpunit/phpunit:$(bench_locked phpunit/phpunit)" "infection/infection:$(bench_locked infection/infection)" \
  'nightworksio/mutation-gate:@dev'
composer update --no-interaction --no-progress --with-all-dependencies
composer show | grep -E '^(phpunit/phpunit|infection/infection|nightworksio/mutation-gate) '
printf '.mutation-gate/\nbench-*.json\nbench-*.txt\ninfection.txt\n' >> .gitignore
bench_baselines src
printf '{"source": {"directories": ["src"]}, "mutators": {"@default": true}}\n' > infection.json
printf '{"runner": {"use": "infection"}, "trees": [{"path": "src"}]}\n' > bench-infection.json
printf '{"runner": {"use": "phpunit", "workers": "fork"}, "trees": [{"path": "src"}]}\n' > bench-phpunit.json
# Plain Infection writes its JSON log only where its config names one.
jq '.logs.json = "bench-infection-log.json"' infection.json > bench-plain-infection.json
bench_commit "symfony/demo"
bench_plain_copy
vendor/bin/mutation-gate infection:patch

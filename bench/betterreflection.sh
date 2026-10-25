#!/usr/bin/env bash
# Prepares Roave/BetterReflection, in its directory, for
# bench/betterreflection.json's arms over src/SourceLocator/Type: the gate
# from this checkout and the Infection its tools lock holds, so both arms
# that mutate with Infection run one release of it.
set -euo pipefail
source "${GITHUB_WORKSPACE}/bench/gate.sh"
infection=$(jq -r '.packages[] | select(.name == "infection/infection") | .version' tools/composer.lock)
repository=$(jq -cn --arg url "${GITHUB_WORKSPACE}" '{type: "path", url: $url, options: {symlink: false}}')
composer config --unset platform.php
composer config repositories.gate "${repository}"
composer config minimum-stability dev
composer config prefer-stable true
composer config allow-plugins.infection/extension-installer false
composer require --dev --no-interaction --no-progress --no-update \
  "infection/infection:${infection}" 'nightworksio/mutation-gate:@dev'
composer update --no-interaction --no-progress
bench_versions phpunit/phpunit infection/infection nightworksio/mutation-gate
printf '.mutation-gate/\nbench-*.json\nbench-*.txt\ninfection.txt\n' >> .gitignore
bench_baselines src
# Each arm mutates src/SourceLocator/Type alone, judged by the whole suite run
# under coverage of src, as the project's own configs have it: plain
# Infection by --filter, and the gate by excluding the rest of src from its
# tree. Infection's own ignores stand, as plain Infection honours them, so
# both arms that mutate with Infection make the same mutants.
rest='"src/*.php", "src/Identifier/**", "src/NodeCompiler/**", "src/Reflection/**", "src/Reflector/**", "src/Util/**",
  "src/SourceLocator/*.php", "src/SourceLocator/Ast/**", "src/SourceLocator/Exception/**",
  "src/SourceLocator/Located/**", "src/SourceLocator/SourceStubber/**"'
printf '{"runner": {"use": "infection"}, "trees": [{"path": "src", "exclude": [%s]}], "ignores": {"native": "allow"}}\n' \
  "${rest}" > bench-infection.json
printf '{"runner": {"use": "phpunit", "workers": "fork"}, "trees": [{"path": "src", "exclude": [%s]}]}\n' \
  "${rest}" > bench-phpunit.json
# Infection reads infection.json before infection.json.dist, as the gate
# does: the project's own, without PHPStan, which reports two errors at the
# pinned commit and so stops Infection before it starts.
jq 'del(.staticAnalysisTool, .phpStan)' infection.json.dist > infection.json
# Plain Infection writes its JSON log only where its config names one.
jq '.logs.json = "bench-infection-log.json"' infection.json > bench-plain-infection.json
bench_commit "Roave/BetterReflection"
bench_plain_copy
vendor/bin/mutation-gate infection:patch

#!/usr/bin/env bash
# Prepares pinkary.com, in its directory, for bench/pinkary.json's arms: its
# locked packages, the gate from this checkout beside them, its front end
# built and its .env made, as its own CI does. Its suite runs on the MySQL
# service the job starts.
set -euo pipefail
source "${GITHUB_WORKSPACE}/bench/gate.sh"
repository=$(jq -cn --arg url "${GITHUB_WORKSPACE}" '{type: "path", url: $url, options: {symlink: false}}')
composer config repositories.gate "${repository}"
# laravel/vet refuses a package it cannot fetch over https, as the gate from this checkout is.
composer config allow-plugins.laravel/vet false
composer install --no-interaction --no-progress
# Without scripts: its post-update hook runs laravel/boost, whose commands
# exist only once the .env this script makes later says the app is local.
composer require --dev --no-interaction --no-progress --no-scripts 'nightworksio/mutation-gate:@dev'
composer show | grep -E '^(pestphp/pest|pestphp/pest-plugin-mutate|phpunit/phpunit|nightworksio/mutation-gate) '
npm install --no-audit --no-fund
npm run build
cp .env.example .env
php artisan key:generate
printf '.mutation-gate/\nbench-*.json\n' >> .gitignore
# Its "do not renders trending questions" test leaves the question's answer
# date to the factory, which draws one from the past year, and fails when it
# falls within the week the trending feed reads: pinned past that week.
TRENDING=$'$questionContent = \'Is this a trending question?\';\n\n    Question::factory()->create([\n' \
PINNED=$'        \'answer_created_at\' => now()->subDays(8),\n' \
perl -0pi -e 's/(\Q$ENV{TRENDING}\E)/$1$ENV{PINNED}/ or die "the trending test is not as pinned\n"' \
  tests/Unit/Livewire/Home/TrendingTest.php
# Each arm mutates app/Actions alone, judged by the whole suite: plain Pest by
# --path, and the gate by excluding the rest of what phpunit.xml's <source>
# lists, app, config and routes, as the trees a config declares are laid over
# those.
rest=""
for entry in app/*; do
  [[ ${entry} == app/Actions ]] && continue
  [[ -d ${entry} ]] && entry="${entry}/**"
  rest="${rest:+${rest}, }\"${entry}\""
done
bench_baselines app config routes
jq -n --argjson rest "[${rest}]" '{runner: "pest", trees: [
  {path: "app", exclude: $rest}, {path: "config", exclude: ["config/**"]}, {path: "routes", exclude: ["routes/**"]}
]}' > bench-pest.json
bench_commit "pinkary.com"
bench_plain_copy
vendor/bin/mutation-gate pest:patch

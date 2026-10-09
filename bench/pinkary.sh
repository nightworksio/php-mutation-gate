#!/usr/bin/env bash
# Prepares pinkary.com, in its directory, for bench/pinkary.json's arms: its
# locked packages, the gate from this checkout beside them, its front end
# built and its .env made, as its own CI does. Its suite runs on the MySQL
# service the job starts.
set -euo pipefail
source "${GITHUB_WORKSPACE}/bench/gate.sh"
repository=$(jq -cn --arg url "${GITHUB_WORKSPACE}" '{type: "path", url: $url, options: {symlink: true}}')
composer config repositories.gate "${repository}"
composer install --no-interaction --no-progress
# laravel/vet refuses a package it cannot fetch over https, as the gate from
# this checkout is; the harness turns it off before requiring the gate.
composer config allow-plugins.laravel/vet false
# The app's post-update-cmd scripts update its AI tooling, which a benchmark
# needs none of; Laravel's package discovery is the one it does need.
composer require --dev --no-interaction --no-progress --no-scripts 'nightworksio/mutation-gate:@dev'
php artisan package:discover
composer show | grep -E '^(pestphp/pest|pestphp/pest-plugin-mutate|phpunit/phpunit|nightworksio/mutation-gate) '
npm install --no-audit --no-fund
npm run build
cp .env.example .env
php artisan key:generate
printf '.mutation-gate/\nbench-*.json\n' >> .gitignore
bench_baselines app/Actions
printf '{"runner": "pest", "trees": [{"path": "app/Actions"}]}\n' > bench-pest.json
bench_commit "pinkary.com"
bench_plain_copy
vendor/bin/mutation-gate pest:patch

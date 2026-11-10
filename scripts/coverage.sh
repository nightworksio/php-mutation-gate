#!/bin/sh
# Every suite but Guards, in parallel, under pcov, with every line of src and
# of each plugin's src held to 100% (G7). Its arguments are passed on to Pest.
#
# pcov collects from the root, for src and every plugin's src, and each parallel
# worker is handed the same settings, or it would collect from src alone.
#
# While src holds no PHP file there is no line to cover, and PHPUnit refuses to
# process coverage at all, failing the run on its own warning. The suite then
# runs without coverage, and the arguments that ask for a coverage report are
# left out.

set -eu

# Where STREAMED names the suite a full early run's streams left (ADR-0135
# decision 8) and they can stand in, the suites they ran are taken from them:
# the rest runs under pcov, its report and the streams' lines are merged, and
# the whole is held to the same floor. Anything short of that, the floor
# included, runs the whole suite below, which alone can fail the run on a
# streamed test.
if [ -n "${STREAMED:-}" ] && excluded=$(php scripts/streamed-suite.php stands-in "${STREAMED}"); then
	clover=coverage/clover.xml
	junit=coverage/junit.xml
	for argument do
		case "$argument" in
		--coverage-clover=*) clover="${argument#--coverage-clover=}" ;;
		--log-junit=*) junit="${argument#--log-junit=}" ;;
		esac
	done
	rest=.mutation-gate/rest/coverage.php
	mkdir -p "$(dirname "${rest}")" "$(dirname "${clover}")" "$(dirname "${junit}")" .mutation-gate/ran
	php -d pcov.enabled=1 -d pcov.directory=. -d 'pcov.exclude=~/(vendor|tests)/~' vendor/bin/pest --parallel \
		--passthru-php="'-d' 'pcov.directory=.' '-d' 'pcov.exclude=~/(vendor|tests)/~'" \
		--exclude-testsuite="${excluded}" --coverage-php="${rest}" --log-junit="${junit}"
	if php scripts/streamed-suite.php merge "${STREAMED}" "${rest}" .mutation-gate/ran/coverage.php "${clover}" "${junit}"; then
		exit 0
	fi
	echo "The streams' lines and the rest's run leave a line uncovered, so the whole suite runs."
fi

if [ -n "$(find src -name '*.php' -print -quit)" ]; then
	exec php -d pcov.enabled=1 -d pcov.directory=. -d 'pcov.exclude=~/(vendor|tests)/~' vendor/bin/pest --parallel \
		--passthru-php="'-d' 'pcov.directory=.' '-d' 'pcov.exclude=~/(vendor|tests)/~'" \
		--exclude-testsuite=Guards --coverage --min=100 "$@"
fi

echo "src holds no PHP file, so there is no line to cover; the suite runs without coverage."

for argument do
	shift
	case "$argument" in
	--coverage*) continue ;;
	esac
	set -- "$@" "$argument"
done

exec php vendor/bin/pest --parallel --exclude-testsuite=Guards "$@"

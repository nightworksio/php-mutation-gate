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

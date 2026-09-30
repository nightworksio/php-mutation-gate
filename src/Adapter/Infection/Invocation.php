<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function addcslashes;
use function implode;
use function is_file;

use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JUnitLog;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function preg_match;
use function sprintf;

/**
 * The PHPUnit and Infection command lines the adapter runs, and the files
 * they leave in the adapter's own directory. Infection always reads a
 * coverage directory the gate chose, so it never runs an opening suite of
 * its own.
 */
final readonly class Invocation
{
    /** The config the gate writes for each run of Infection. */
    public const string CONFIG = 'infection.json5';

    public const string JSON = 'logs/infection.json';

    public const string TEXT = 'logs/infection.log';

    public const string TMP = 'tmp';

    /** Where the adapter runs the suite under coverage for a mutation run of its own. */
    public const string COVERAGE = 'coverage';

    /** PHPUnit's XML coverage in a coverage directory, where Infection's `--coverage` looks for it. */
    public const string XML = 'coverage-xml';

    /** PHPUnit's JUnit log in a coverage directory, where Infection's `--coverage` looks for it. */
    public const string JUNIT = JUnitLog::NAME;

    private const string INFECTION = 'vendor/bin/infection';


    /** An option with its value, `--name=value`. */
    private const string OPTION = '/^(?<name>-{1,2}[^=\s"]+)=(?<value>.*)$/s';

    /** Whether Infection is installed in the project, where the adapter runs it from. */
    public static function runnableIn(Project $project): bool
    {
        return is_file($project->absolute(Path::of(self::INFECTION)));
    }

    public static function listingGroups(Project $project, OwnConfig $config): Command
    {
        return Command::php(
            $config->phpunit($project),
            sprintf('--configuration=%s', $config->configDirectory($project)),
            '--list-groups',
            '--colors=never',
        );
    }

    /**
     * A run of no test, started as Infection starts PHPUnit for a mutant
     * (PhpUnitAdapter::getMutantCommandLine): the project's PHPUnit with no
     * PHP options, on a config shaped as Infection shapes a mutant's (see
     * StartUpConfig), whose one suite holds no test file, with the project's
     * extra arguments and a filter that selects no test, passing having run
     * none.
     */
    public static function startingUp(Project $project, OwnConfig $config, string $startUpConfig): Command
    {
        return Command::php(
            $config->phpunit($project),
            sprintf('--configuration=%s', $startUpConfig),
            '--colors=never',
            ...$config->extraArguments(),
            ...self::narrowedTo(Filter::nothing()),
            ...['--do-not-fail-on-empty-test-suite'],
        );
    }

    /** The suite, or the tests that judge a held path, under coverage, writing the layout `--coverage` reads. */
    public static function coverage(
        Project $project,
        OwnConfig $config,
        WholeSuite|Group|Filter $tests,
        DiskPath $directory,
    ): Command {
        return Command::php(
            ...[
                ...$config->phpOptions(),
                $config->phpunit($project),
                sprintf('--configuration=%s', $config->configDirectory($project)),
                sprintf('--coverage-xml=%s', $directory->child(self::XML)->value()),
                sprintf('--log-junit=%s', $directory->child(self::JUNIT)->value()),
                '--colors=never',
                ...$config->extraArguments(),
                ...self::narrowedTo($tests),
            ],
        )->with(['XDEBUG_MODE' => 'coverage']);
    }

    /**
     * Infection over some files, reading the coverage in a directory. The
     * tests that judge a held path narrow each mutant's run as well, and a
     * `#[Holds]` filter runs only the covering test methods, not their whole
     * classes.
     *
     * @param list<string> $paths the files and directories to mutate, by their paths on disk
     */
    public static function mutation(
        Project $project,
        OwnConfig $config,
        WholeSuite|Group|Filter $judgedBy,
        DiskPath $coverage,
        Processes $processes,
        array $paths,
    ): Command {
        $extra = [...$config->extraArguments(), ...self::narrowedTo($judgedBy)];

        return Command::php(
            $project->absolute(Path::of(self::INFECTION)),
            sprintf('--configuration=%s', $project->own(self::CONFIG)),
            sprintf('--threads=%d', $processes->count()),
            '--no-progress',
            '--no-interaction',
            '--with-uncovered',
            '--logger-github=false',
            sprintf('--coverage=%s', $coverage->value()),
            '--skip-initial-tests',
            ...($extra === [] ? [] : [sprintf('--test-framework-extra-args=%s', self::quoted($extra))]),
            ...($judgedBy instanceof Filter ? ['--only-covering-test-cases'] : []),
            ...$paths,
        );
    }

    /** @return list<string> */
    private static function narrowedTo(WholeSuite|Group|Filter $tests): array
    {
        return match (true) {
            $tests instanceof Group => [sprintf('--group=%s', $tests->name())],
            $tests instanceof Filter => [sprintf('--filter=%s', $tests->pattern())],
            default => [],
        };
    }

    /**
     * Arguments as one string Infection splits back into the same arguments,
     * with each value in double quotes and its quotes and backslashes escaped.
     * An option keeps its name outside the quotes, as Infection's own reading
     * of `--filter=` and the other options it leaves out of a mutant's run
     * expects.
     *
     * @param list<string> $arguments
     */
    private static function quoted(array $arguments): string
    {
        $quoted = [];

        foreach ($arguments as $argument) {
            $quoted[] = preg_match(self::OPTION, $argument, $option) === 1
                ? sprintf('%s="%s"', $option['name'], addcslashes($option['value'], '"\\'))
                : sprintf('"%s"', addcslashes($argument, '"\\'));
        }

        return implode(' ', $quoted);
    }
}

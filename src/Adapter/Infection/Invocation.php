<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function addcslashes;
use function array_map;
use function implode;
use function is_file;

use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\PcovReach;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JUnitLog;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function preg_match;
use function preg_quote;
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

    /** The mutants a patched Infection stopped at their silence limit (see Silenced). */
    public const string SILENCED = 'logs/silenced.jsonl';

    public const string TMP = 'tmp';

    /** PHPUnit's XML coverage in a coverage directory, where Infection's `--coverage` looks for it. */
    public const string XML = 'coverage-xml';

    /** PHPUnit's JUnit log in a coverage directory, where Infection's `--coverage` looks for it. */
    public const string JUNIT = JUnitLog::NAME;

    private const string INFECTION = 'vendor/bin/infection';

    /** A PHPUnit filter that selects some tests by their ids, and each row of a data set of theirs. */
    private const string SELECTING = '/^(?:%s)(?: with data set .*)?$/';


    /** An option with its value, `--name=value`. */
    private const string OPTION = '/^(?<name>-{1,2}[^=\s"]+)=(?<value>.*)$/s';

    /** Whether Infection is installed in the project, where the adapter runs it from. */
    public static function runnableIn(Project $project): bool
    {
        return is_file($project->absolute(Path::of(self::INFECTION)));
    }

    /** These suites' tests and the groups each is in, as the project's PHPUnit lists them into a file, running none. */
    public static function listing(Project $project, OwnConfig $config, Suites $suites, string $file): Command
    {
        return Command::php(
            $config->phpunit($project),
            sprintf('--configuration=%s', $config->configDirectory($project)),
            sprintf('%s=%s', PhpUnitOption::ListTestsXml->value, $file),
            PhpUnitOption::NoColors->value,
            ...PhpUnitOption::inSuites($suites),
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
            PhpUnitOption::NoColors->value,
            ...$config->extraArguments(),
            ...self::narrowedTo(Filter::nothing()),
            ...[PhpUnitOption::DoNotFailOnEmptyTestSuite->value],
        );
    }

    /**
     * An unmutated control's run (ADR-0008, decision 2): PHPUnit with the
     * project's PHPUnit, on a config shaped as Infection shapes a mutant's
     * (see StartUpConfig), whose one suite holds the control's test files,
     * with the project's extra arguments and a filter that selects its tests
     * by the names PHPUnit gives them: a row an id names alone, and every
     * row of a method it names whole.
     */
    public static function controlling(
        Project $project,
        OwnConfig $config,
        string $controlConfig,
        TestIds $tests,
    ): Command {
        $ids = array_map(static fn(TestId $test): string => preg_quote(self::named($test), '/'), [...$tests]);

        return Command::php(
            $config->phpunit($project),
            sprintf('--configuration=%s', $controlConfig),
            PhpUnitOption::NoColors->value,
            ...$config->extraArguments(),
            ...self::narrowedTo(Filter::matching(sprintf(self::SELECTING, implode('|', $ids)))),
        );
    }

    /**
     * The suite, or the tests that judge a held path, under coverage, writing
     * the layout `--coverage` reads and, for the gate's map, the lines no
     * test ran beside it, with pcov collecting from every tree of the
     * project, whatever the project's own PHP options set it to.
     */
    public static function coverage(
        Project $project,
        OwnConfig $config,
        WholeSuite|Group|Filter|TestPaths $tests,
        DiskPath $directory,
        Suites $suites,
        CoverageFor $use,
    ): Command {
        return Command::php(
            ...[
                ...$config->phpOptions(),
                ...PcovReach::under($project->root(), Path::of(Manifest::VENDOR))->options(),
                $config->phpunit($project),
                sprintf('--configuration=%s', $config->configDirectory($project)),
                sprintf('--coverage-xml=%s', $directory->child(self::XML)->value()),
                ...$use === CoverageFor::Map ? [self::clover($directory)] : [],
                sprintf('%s=%s', PhpUnitOption::LogJunit->value, $directory->child(self::JUNIT)->value()),
                PhpUnitOption::NoColors->value,
                ...$config->extraArguments(),
                ...($tests instanceof TestPaths ? self::filesOf($project, $tests) : self::narrowedTo($tests)),
                ...PhpUnitOption::inSuites($suites),
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
        ProcessCount $processes,
        array $paths,
        Suites $suites,
    ): Command {
        $extra = [...$config->extraArguments(), ...self::narrowedTo($judgedBy), ...PhpUnitOption::inSuites($suites)];

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

    /** A test as PHPUnit names it for a filter; an id that names no test method, such as a `.phpt` file's, as it is. */
    private static function named(TestId $test): string
    {
        $method = TestMethod::of($test);

        return $method instanceof TestMethod ? $method->named() : $method->value();
    }

    /** The option that writes the Clover report of the lines no test ran into a coverage directory. */
    private static function clover(DiskPath $directory): string
    {
        return sprintf('%s=%s', PhpUnitOption::CoverageClover->value, $directory->child(MissedLines::FILE)->value());
    }

    /**
     * The test files PHPUnit runs, by their paths on disk, each an argument
     * PHPUnit runs in place of the config's suites.
     *
     * @return list<string>
     */
    private static function filesOf(Project $project, TestPaths $tests): array
    {
        return array_map($project->absolute(...), [...$tests->files()]);
    }

    /** @return list<string> */
    private static function narrowedTo(WholeSuite|Group|Filter $tests): array
    {
        return match (true) {
            $tests instanceof Group => [sprintf('%s=%s', PhpUnitOption::Group->value, $tests->name())],
            $tests instanceof Filter => [sprintf('%s=%s', PhpUnitOption::Filter->value, $tests->pattern())],
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

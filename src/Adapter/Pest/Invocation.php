<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function count;
use function implode;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\CoverageRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function sprintf;

/**
 * The Pest command lines the adapter runs, each with `--no-tia` where Pest's
 * test impact analysis could narrow the run, and none with `--coverage`,
 * whose own report path would win over the gate's.
 */
final readonly class Invocation
{
    /** The coverage map's name in a coverage directory. */
    public const string MAP = 'coverage.php';

    private const string JUNIT = 'junit.xml';

    public static function listingGroups(): Command
    {
        return Command::pest('--list-groups', '--colors=never');
    }

    public static function coverage(CoverageRequest $request, string $directory): Command
    {
        return Command::pest(
            '--parallel',
            sprintf('--processes=%d', $request->processes()->count()),
            '--no-tia',
            sprintf('--coverage-php=%s/%s', $directory, self::MAP),
            sprintf('--log-junit=%s/%s', $directory, self::JUNIT),
            ...self::narrowedTo($request->tests()),
        );
    }

    /**
     * Mutation with `--path`, so the gate and not `covers()` decides what is
     * mutated, and `--no-cache`, so no stale mutant decides a result.
     */
    public static function mutation(MutationRequest $request, WholeSuite|Group $judgedBy, string $results): Command
    {
        return Command::pest(
            '--mutate',
            '--no-cache',
            '--parallel',
            sprintf('--processes=%d', $request->processes()->count()),
            '--no-tia',
            '--colors=never',
            sprintf('--path=%s', self::joined($request->files())),
            ...self::ignoring($request->leftOut()),
            ...self::narrowedTo($judgedBy),
            ...self::applying($request->mutators()),
        )->with([Recorder::RESULTS => $results])->within($request->deadline());
    }

    /** @return list<string> */
    private static function narrowedTo(WholeSuite|Group $tests): array
    {
        return $tests instanceof Group ? [sprintf('--group=%s', $tests->name())] : [];
    }

    /** @return list<string> */
    private static function ignoring(Paths $paths): array
    {
        return count($paths) === 0 ? [] : [sprintf('--ignore=%s', self::joined($paths))];
    }

    /** @return list<string> */
    private static function applying(Mutators $mutators): array
    {
        $named = [];

        foreach ($mutators as $mutator) {
            $named[] = $mutator;
        }

        return $mutators->isAll() ? [] : [sprintf('--mutator=%s', implode(',', $named))];
    }

    private static function joined(Paths $paths): string
    {
        $values = [];

        foreach ($paths as $path) {
            $values[] = $path->value();
        }

        return implode(',', $values);
    }
}

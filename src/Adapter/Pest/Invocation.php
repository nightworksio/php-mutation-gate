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

    /** JUnit's log of a coverage run, in the same directory. */
    public const string JUNIT = 'junit.xml';

    /**
     * What `--ignore` names when the gate leaves nothing out: its own
     * directory, which holds no source, so that a project's own ignore list
     * in `pest()->mutate()` never decides what is mutated.
     */
    private const string NOTHING = '.mutation-gate';

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
     *
     * It passes no `--processes`: pest-plugin-mutate hands that option on to
     * each mutant's own run, which is not parallel and fails on it, so every
     * covered mutant would read as killed. Pest runs as many mutants at once
     * as the machine has cores.
     *
     * The options after `--no-tia` undo what a project's own
     * `pest()->mutate()` could set: covered lines only, a class list, a stop
     * at the first escaped or uncovered mutant, and escaped mutants first.
     */
    public static function mutation(MutationRequest $request, WholeSuite|Group $judgedBy, string $results): Command
    {
        return Command::pest(
            '--mutate',
            '--no-cache',
            '--parallel',
            '--no-tia',
            '--everything',
            '--covered-only=false',
            '--stop-on-untested=false',
            '--stop-on-uncovered=false',
            '--retry=false',
            '--colors=never',
            sprintf('--path=%s', PathList::of($request->files())->joined(',')),
            sprintf('--ignore=%s', self::ignored($request->leftOut())),
            ...self::narrowedTo($judgedBy),
            ...self::applying($request->mutators()),
        )->with([Recorder::RESULTS => $results])->within($request->deadline());
    }

    /** @return list<string> */
    private static function narrowedTo(WholeSuite|Group $tests): array
    {
        return $tests instanceof Group ? [sprintf('--group=%s', $tests->name())] : [];
    }

    private static function ignored(Paths $paths): string
    {
        return count($paths) === 0 ? self::NOTHING : PathList::of($paths)->joined(',');
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
}

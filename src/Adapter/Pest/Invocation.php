<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function count;
use function implode;
use function is_file;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JUnitLog;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function sprintf;

/**
 * The Pest command lines the adapter runs, each with `--no-tia` where Pest's
 * test impact analysis could narrow the run, and none with `--coverage`,
 * whose own report path would win over the gate's. Each runs Pest's own
 * script in the vendor directory, which Composer's `bin-dir` cannot move.
 */
final readonly class Invocation
{
    /** The coverage map's name in a coverage directory. */
    public const string MAP = 'coverage.php';

    /** JUnit's log of a coverage run, in the same directory. */
    public const string JUNIT = JUnitLog::NAME;

    /**
     * Where a run is narrowed to a group: a run with none of the group's
     * tests passes. Under `--parallel`, Pest can sum a run that ran every
     * test of the group as one with no tests, and fail it.
     */
    public const string EMPTY_PASSES = '--do-not-fail-on-empty-test-suite';

    /** Pest's script, in the directory Composer installed the project's packages in. */
    private const string SCRIPT = '%s/pestphp/pest/bin/pest';

    private function __construct(private string $script)
    {
    }

    /** Pest's command lines, where Composer installed Pest in this vendor directory. */
    public static function installedIn(Path $vendor): self
    {
        return new self(sprintf(self::SCRIPT, $vendor->value()));
    }

    public function listingGroups(Withheld $withheld): Command
    {
        return Command::pest($this->script, $withheld, '--list-groups', '--colors=never');
    }

    /** Every test, listed and never run, with the plugin naming each in a file. */
    public function listingTests(Withheld $withheld, string $names): Command
    {
        return Command::pest($this->script, $withheld, '--list-tests', '--colors=never')
            ->with([GateVariable::Names->value => $names]);
    }

    /** Whether Pest's script is in the project, where the gate runs it from. */
    public function isIn(Project $project): bool
    {
        return is_file($project->absolute(Path::of($this->script)));
    }

    public function coverage(CoverageRun $request, string $directory): Command
    {
        return Command::pest(
            $this->script,
            $request->withheld(),
            '--parallel',
            sprintf('--processes=%d', $request->processes()->count()),
            '--no-tia',
            sprintf('--coverage-php=%s/%s', $directory, self::MAP),
            sprintf('--log-junit=%s/%s', $directory, self::JUNIT),
            ...$this->narrowedTo($request->tests()),
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
    public function mutation(MutationRequest $request, WholeSuite|Group $judgedBy, string $results): Command
    {
        return Command::pest(
            $this->script,
            $request->withheld(),
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
            sprintf('--ignore=%s', $this->ignored($request->leftOut())),
            ...$this->narrowedTo($judgedBy),
            ...$this->applying($request->mutators()),
        )->with([GateVariable::Results->value => $results])->within($request->deadline());
    }

    /**
     * A run of no test, started as pest-plugin-mutate starts a mutant's own
     * run: Pest with `--bail` and a filter, which loads every test file and
     * then runs none.
     */
    public function startingUp(Withheld $withheld): Command
    {
        return Command::pest(
            $this->script,
            $withheld,
            '--no-tia',
            '--bail',
            '--colors=never',
            ...$this->narrowedTo(Filter::nothing()),
        );
    }

    /**
     * The tests in some files, one after another, stopping at the first that
     * fails, narrowed to a holding group where one judges: the run that judges
     * a mutant of a line that is not executable through Pest's override.
     */
    public function judging(Paths $tests, WholeSuite|Group $judgedBy, Withheld $withheld): Command
    {
        $files = [];

        foreach ($tests as $test) {
            $files[] = $test->value();
        }

        return Command::pest(
            $this->script,
            $withheld,
            '--no-tia',
            '--bail',
            '--colors=never',
            ...$this->narrowedTo($judgedBy),
            ...$files,
        );
    }

    /** @return list<string> */
    private function narrowedTo(WholeSuite|Group|Filter $tests): array
    {
        return match (true) {
            $tests instanceof Group => [sprintf('--group=%s', $tests->name()), self::EMPTY_PASSES],
            $tests instanceof Filter => [sprintf('--filter=%s', $tests->pattern()), self::EMPTY_PASSES],
            default => [],
        };
    }

    /**
     * What `--ignore` names: these paths, or, where the gate leaves nothing
     * out, its own directory, which holds no source, so that a project's own
     * ignore list in `pest()->mutate()` never decides what is mutated.
     */
    private function ignored(Paths $paths): string
    {
        return count($paths) === 0 ? Workspace::root()->value() : PathList::of($paths)->joined(',');
    }

    /** @return list<string> */
    private function applying(Mutators $mutators): array
    {
        $named = [];

        foreach ($mutators as $mutator) {
            $named[] = $mutator;
        }

        return $mutators->isAll() ? [] : [sprintf('--mutator=%s', implode(',', $named))];
    }
}

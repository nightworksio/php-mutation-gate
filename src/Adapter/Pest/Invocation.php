<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_map;
use function count;
use function implode;
use function is_file;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Core\Coverage\PhpReport;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\JUnitLog;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
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
    public const string MAP = PhpReport::NAME;

    /** JUnit's log of a coverage run, in the same directory. */
    public const string JUNIT = JUnitLog::NAME;

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
        return Command::pest(
            $this->script,
            $withheld,
            PhpUnitOption::ListGroups->value,
            PhpUnitOption::NoColors->value,
        );
    }

    /** Every test, listed and never run, with the plugin naming each in a file. */
    public function listingTests(Withheld $withheld, string $names): Command
    {
        return Command::pest($this->script, $withheld, '--list-tests', PhpUnitOption::NoColors->value)
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
            sprintf('%s=%s/%s', PhpUnitOption::CoveragePhp->value, $directory, self::MAP),
            sprintf('%s=%s/%s', PhpUnitOption::LogJunit->value, $directory, self::JUNIT),
            ...$this->covering($request->tests()),
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
     * `--mutator` names the bridges to the registered mutators a config turns
     * on beside Pest's own (see Bridges).
     */
    public function mutation(
        MutationRequest $request,
        WholeSuite|Group $judgedBy,
        string $results,
        Bridges $bridges = new Bridges(),
    ): Command {
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
            PhpUnitOption::NoColors->value,
            sprintf('--path=%s', PathList::of($request->files())->joined(',')),
            sprintf('--ignore=%s', $this->ignored($request->leftOut())),
            ...$this->narrowedTo($judgedBy),
            ...$this->applying($request->mutators(), $bridges),
        )->with([
            GateVariable::Results->value => $results,
            GateVariable::KillMatrix->value => $request->search()->matrix()->value,
        ])->within($request->deadline());
    }

    /**
     * A run of no test, started as pest-plugin-mutate starts a mutant's own
     * run (MutationTest::start): Pest with `--bail` and a filter, which loads
     * every test file and then runs none, in the environment it gives a
     * mutant under `--parallel`, naming a file and the copy it serves in the
     * file's place.
     */
    public function startingUp(Withheld $withheld, string $original, string $copy): Command
    {
        return Command::pest(
            $this->script,
            $withheld,
            '--no-tia',
            '--bail',
            PhpUnitOption::NoColors->value,
            ...$this->narrowedTo(Filter::nothing()),
        )->with([
            Recorder::MUTANT => $original,
            Recorder::MUTATED => $copy,
            'PARATEST' => '1',
            'TEST_TOKEN' => '0',
            'UNIQUE_TEST_TOKEN' => '0_start-up',
            'LARAVEL_PARALLEL_TESTING' => '1',
        ]);
    }

    /**
     * The tests in some files, one after another, stopping at the first that
     * fails, narrowed to what judges: the run that judges a mutant of a line
     * that is not executable through Pest's override, and the run on the
     * unmutated code a narrowed kill stands on (see NarrowedKills). The
     * options given come before the files, such as the log a run writes.
     */
    public function judging(
        Paths $tests,
        WholeSuite|Group|Filter $judgedBy,
        Withheld $withheld,
        string ...$options,
    ): Command {
        $files = [];

        foreach ($tests as $test) {
            $files[] = $test->value();
        }

        return Command::pest(
            $this->script,
            $withheld,
            '--no-tia',
            '--bail',
            PhpUnitOption::NoColors->value,
            ...$this->narrowedTo($judgedBy),
            ...$options,
            ...$files,
        );
    }

    /**
     * What narrows a coverage run: a group, a filter, or the test files Pest
     * runs in place of its suite, as paths from the project's root.
     *
     * @return list<string>
     */
    private function covering(WholeSuite|Group|Filter|TestPaths $tests): array
    {
        if (! $tests instanceof TestPaths) {
            return $this->narrowedTo($tests);
        }

        return [
            ...array_map(static fn(Path $file): string => $file->value(), [...$tests->files()]),
            PhpUnitOption::DoNotFailOnEmptyTestSuite->value,
        ];
    }

    /**
     * The options that narrow a run to a group or a filter, where a run with
     * none of their tests passes: under `--parallel`, Pest can sum a run that
     * ran every test of the group as one with no tests, and fail it.
     *
     * @return list<string>
     */
    private function narrowedTo(WholeSuite|Group|Filter $tests): array
    {
        $empty = PhpUnitOption::DoNotFailOnEmptyTestSuite->value;

        return match (true) {
            $tests instanceof Group => [sprintf('%s=%s', PhpUnitOption::Group->value, $tests->name()), $empty],
            $tests instanceof Filter => [sprintf('%s=%s', PhpUnitOption::Filter->value, $tests->pattern()), $empty],
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
    private function applying(Mutators $mutators, Bridges $bridges): array
    {
        $named = $bridges->applying($mutators);

        return $named === [] ? [] : [sprintf('--mutator=%s', implode(',', $named))];
    }
}

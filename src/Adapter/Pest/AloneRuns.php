<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_combine;
use function array_key_exists;
use function array_keys;
use function array_map;

use Closure;

use function count;
use function dirname;
use function file_get_contents;
use function getmypid;
use function implode;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Test\JUnitFailures;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;
use function strval;

/**
 * The runs that vouch for kills on the unmutated code (see Control): the
 * tests of each set of files a mutant's own run was narrowed to (see
 * NarrowedKills), or the tests a run again with every test file ran, each
 * with the file its mutant changes served unmutated through Pest's override,
 * as the mutant's run served its copy, logging JUnit to a file of its own,
 * which names the tests that failed. Each runs once for each key in a
 * process, side by side in the places of the request's pool as the mutants'
 * own runs were.
 *
 * @internal
 */
final readonly class AloneRuns
{
    /** Why a control never ran where no time was left for it. */
    private const string NO_TIME = 'no time was left for it';

    /** Where the parts of a run's key are joined. */
    private const string BETWEEN = "\n";

    /** Where the run at a position writes its JUnit log, among the adapter's own files. */
    private const string LOG = 'pest/alone/junit-%d.xml';

    public function __construct(private Project $project, private Shell $shell, private Remembered $remembered)
    {
    }

    /**
     * How each control ended, in the order given, the tests that failed as
     * its log names them: none run where no time is left, none started once
     * the time left has run out, and none run whose file cannot be served
     * unmutated, saying why.
     *
     * @param  non-empty-list<Control> $controls
     * @param  string                  $results  the run's results file, beside which the unmutated copies are written
     * @return list<ControlRun>
     */
    public function ended(array $controls, MutationRequest $request, Seconds|Unlimited $left, string $results): array
    {
        if ($left instanceof Seconds && $left->seconds() <= 0.0) {
            return array_map(static fn(): ControlRun => ControlRun::unrun(self::NO_TIME), $controls);
        }

        $withheld = $request->withheld();
        $invocation = Invocation::of($this->project);
        $commands = [];
        $keys = [];
        $unserved = [];

        foreach ($controls as $at => $control) {
            $served = ServedOriginal::of($this->project, dirname($results), $control->source());

            if ($served instanceof CannotJudge) {
                $unserved[$at] = ControlRun::unrun($served->why());

                continue;
            }

            $suites = $this->suitesOf($control, $request);
            $judging = static fn(string ...$options): Command => $served->onto($invocation->judging(
                $control->tests(),
                $control->judgedBy(),
                $withheld,
                ...[...$suites, ...$options],
            )->within($left));
            $keys[$at] = implode(self::BETWEEN, [$withheld->pattern(), $served->key(), ...$judging()->arguments()]);
            $commands[$keys[$at]] = $judging;
        }

        $slots = WorkerSlots::of($request->pool()->processes(), strval(getmypid()));
        $ran = $commands === [] ? [] : $this->remembered->baselines(
            array_keys($commands),
            /**
             * @param  non-empty-list<string> $unknown
             * @return array<int, ControlRun>
             */
            fn(array $unknown): array => $this->logged(
                $slots,
                $left,
                array_map(static fn(string $key): Closure => $commands[$key], $unknown),
            ),
        );
        $found = array_combine(array_keys($commands), $ran);

        return array_map(
            static fn(int $at): ControlRun => array_key_exists($at, $keys) ? $found[$keys[$at]] : $unserved[$at],
            array_keys($controls),
        );
    }

    /**
     * How each of these runs ended, side by side, each logging JUnit to a
     * file of its own, by its position; none for one never started. A run
     * whose log has no place runs unlogged.
     *
     * @param  list<Closure(string...): Command> $commands each run, given the options it adds
     * @return array<int, ControlRun>
     */
    private function logged(WorkerSlots $slots, Seconds|Unlimited $left, array $commands): array
    {
        $logs = [];
        $logging = [];

        foreach ($commands as $at => $command) {
            $log = $this->project->fresh(sprintf(self::LOG, $at));
            $logs[$at] = $log;
            $logging[] = is_string($log)
                ? $command(sprintf('%s=%s', PhpUnitOption::LogJunit->value, $log))
                : $command();
        }

        $runs = [];

        foreach ($this->shell->sideBySide($slots, $left, ...$logging) as $at => $ran) {
            $log = $logs[$at];
            $failing = JUnitFailures::of(is_string($log) && is_file($log) ? file_get_contents($log) : false);
            $runs[$at] = ControlRun::ofProcess($ran, $failing);
        }

        return $runs;
    }

    /**
     * The suites a control of no test file runs: the judging suites, and the
     * holding suites too where its tests hold a unit; none named for one of
     * test files, which runs those files.
     *
     * @return list<string>
     */
    private function suitesOf(Control $control, MutationRequest $request): array
    {
        $suites = $request->narrowing()->suitesFor($control->judgedBy());

        return count($control->tests()) === 0 ? PhpUnitOption::inSuites($suites) : [];
    }
}

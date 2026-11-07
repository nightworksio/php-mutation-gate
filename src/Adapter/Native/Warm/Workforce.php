<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native\Warm;

use function array_keys;
use function array_map;
use function array_search;
use function array_slice;
use function array_unique;
use function array_values;
use function getmypid;
use function hrtime;
use function is_file;
use function is_int;
use function max;
use function microtime;

use NightWorksIO\MutationGate\Adapter\Native\Invocation;
use NightWorksIO\MutationGate\Adapter\Native\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Native\MutantRun;
use NightWorksIO\MutationGate\Adapter\Native\PreparedRun;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Core\Doctor\WarmRefusal;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Native\Framework;
use NightWorksIO\MutationGate\Core\Native\Shell;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnScript;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;
use function strval;
use function trim;
use function unlink;

/**
 * A run's warm workers (ADR-0023): one in each of the request's places,
 * side by side, each booting once and claiming the runs in turn from a
 * workplace made for this run alone, so nothing an earlier run left there is
 * read as this one's. The runs are mutants' or unmutated controls'. Once the
 * workers are done, each run a child ended is judged, every other is left to
 * a fresh process, and the reason a worker's boot was refused is warned of
 * once.
 *
 * @internal
 */
final readonly class Workforce
{
    /** The workplace of one run, among the adapter's files, named by the gate's process and when it began. */
    private const string WORKPLACE = 'warm/%d-%d';

    private const string FAILED = 'A warm worker failed, so the mutants it left ran fresh. It said: %s';

    public function __construct(
        private Project $project,
        private Shell $shell,
        private Invocation $invocation,
        private MemoryScan $scan,
        private MutantRun $run,
        private Framework $framework,
    ) {
    }

    /** When a run that has this long stops starting runs, on `hrtime`'s clock: never, where it has no deadline. */
    public static function endingIn(Seconds|Unlimited $deadline): int|float
    {
        return $deadline instanceof Seconds ? hrtime(as_number: true) + $deadline->nanoseconds() : PHP_INT_MAX;
    }

    /**
     * The mutants of the runs, by their positions, as warm workers' children
     * judged them, none started once `hrtime` reaches the end.
     *
     * @param array<int, PreparedRun> $runs by position
     */
    public function judged(MutationRequest $request, int|float $end, array $runs): Forked
    {
        $ends = $this->ended($request, $end, $runs);
        $judged = [];
        $evidence = Evidences::none();

        foreach ($ends->all() as $at => $ran) {
            $mutant = $this->run->finished($runs[$at], $ran);
            $judged[$at] = $mutant;
            $evidence = $evidence->with($mutant->id(), $this->run->evidenced($runs[$at], $ran, $mutant));
        }

        return Forked::of($judged, $ends->warnings(), $evidence);
    }

    /**
     * How each of the runs, by their positions, ended in warm workers'
     * children, none started once `hrtime` reaches the end.
     *
     * @param array<int, PreparedRun> $runs by position
     */
    public function ended(MutationRequest $request, int|float $end, array $runs): WarmEnds
    {
        $script = $this->framework->workerScript();

        if ($runs === [] || $script instanceof NotGiven) {
            return WarmEnds::none();
        }

        $workplace = Workplace::at($this->project->own(sprintf(self::WORKPLACE, getmypid(), hrtime(as_number: true))));
        $ends = $workplace->opened($this->jobOf($end, array_values($runs)))
            ? $this->worked($workplace, $script, $request, $this->deadlineOf($end, $runs, $request), array_keys($runs))
            : WarmEnds::none();
        $workplace->removed();

        return $ends;
    }

    /** @param list<int> $positions each run's position, in the order the job holds them */
    private function worked(
        Workplace $workplace,
        OwnScript $script,
        MutationRequest $request,
        Seconds|Unlimited $deadline,
        array $positions,
    ): WarmEnds {
        $slots = WorkerSlots::of($request->pool()->processes(), strval(getmypid()));
        $commands = [];

        foreach (array_keys([...$slots]) as $place) {
            $worker = $this->invocation->worker($script, $workplace, $place, $request->withheld());
            $commands[] = $this->scan->onto($worker->within($deadline));
        }

        $ends = $this->shell->sideBySide($slots, Unlimited::time(), ...$commands);
        $ran = [];
        $peaks = [];

        foreach ($positions as $at => $position) {
            $ended = End::ranIn($workplace, $at);
            $peak = End::peakIn($workplace, $at);
            $ran += $ended instanceof Ran ? [$position => $ended] : [];
            $peaks += $peak instanceof MemoryCap ? [$position => $peak] : [];
        }

        $warnings = $this->refusals($workplace, $ends);
        $this->kept($warnings);

        return WarmEnds::of($ran, $peaks, $warnings);
    }

    /**
     * Why the workers forked nothing, kept for `doctor` in place of what an
     * earlier run kept; or nothing kept, where they forked.
     */
    private function kept(Warnings $warnings): void
    {
        $kept = $this->project->own(WarmRefusal::NAME);

        foreach ($warnings as $warning) {
            $this->project->written(WarmRefusal::NAME, $warning->text());

            return;
        }

        if (is_file($kept)) {
            unlink($kept);
        }
    }

    /** @param list<PreparedRun> $runs */
    private function jobOf(int|float $end, array $runs): Job
    {
        $warm = [];
        $mutated = [];

        foreach ($runs as $run) {
            $warm[] = $this->warmRunOf($run);
            $mutated[] = $run->files()->original();
        }

        return Job::of(
            $this->project->autoloader(),
            $this->framework->config(),
            array_values(array_unique($mutated)),
            $end === PHP_INT_MAX ? NotGiven::value() : microtime(as_float: true) + $this->secondsTo($end),
            $warm,
        );
    }

    /** A prepared run as a worker's child runs it: the framework's own command line, from its script on. */
    private function warmRunOf(PreparedRun $run): WarmRun
    {
        $arguments = $run->command()->arguments();
        $script = array_search($this->framework->script(), $arguments, strict: true);
        $files = $run->files();

        return WarmRun::of(
            is_int($script) ? array_slice($arguments, $script) : $arguments,
            $run->command()->environment(),
            $run->limit()->seconds(),
            $files->original(),
            $files->mutated(),
            $files->guard(),
            $run->command()->silence(),
        );
    }

    /**
     * How long the workers may run: until the end, then the longest run's
     * limit and some slack; with no deadline, where there is no end.
     *
     * @param non-empty-array<int, PreparedRun> $runs
     */
    private function deadlineOf(int|float $end, array $runs, MutationRequest $request): Seconds|Unlimited
    {
        $longest = max(array_map(static fn(PreparedRun $run): float => $run->limit()->seconds(), $runs));

        return $end === PHP_INT_MAX
            ? Unlimited::time()
            : Seconds::of(
                max(0.0, $this->secondsTo($end)) + $longest + $request->pool()->pace()->warmSlack()->seconds(),
            );
    }

    /** The seconds from now to a reading of `hrtime`. */
    private function secondsTo(int|float $end): float
    {
        return ($end - hrtime(as_number: true)) / Seconds::NANOSECONDS;
    }

    /**
     * One warning for each reason the run warns of that a worker forked
     * nothing for, or forked no more for: the guard's refusal, or how the
     * worker failed, with what it said.
     */
    private function refusals(Workplace $workplace, ProcessEnds $ends): Warnings
    {
        $reasons = [];

        foreach ($ends as $place => $ended) {
            $refusal = Refusal::readFrom($workplace->refused($place));
            $reason = match (true) {
                $refusal instanceof Refusal => $refusal->warns() ? $refusal->reason() : '',
                $ended->succeeded() => '',
                default => sprintf(self::FAILED, trim(Printed::whole($workplace, $place))),
            };
            $reasons += $reason === '' ? [] : [$reason => $reason];
        }

        return Warnings::of(...array_map(Warning::noting(...), $reasons));
    }
}

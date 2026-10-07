<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

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

use NightWorksIO\MutationGate\Adapter\PhpUnit\Invocation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MemoryScan;
use NightWorksIO\MutationGate\Adapter\PhpUnit\MutantRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\PreparedRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Shell;
use NightWorksIO\MutationGate\Core\Doctor\WarmRefusal;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\NotGiven;
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
 * A mutation run's warm workers (ADR-0023, decisions 12 to 14): one in each
 * of the request's places, side by side, each booting once and claiming the
 * runs in turn from a workplace made for this run alone, so nothing an
 * earlier run left there is read as this one's. Once they are done, each run
 * a child ended is judged, every other is left to a fresh process, and the
 * reason a worker's boot was refused is warned of once.
 */
final readonly class Workforce
{
    /** The workplace of one run, among the adapter's files, named by the gate's process and when it began. */
    private const string WORKPLACE = 'warm/%d-%d';

    private const string FAILED = 'A warm worker failed, so the mutants it left ran fresh. It said: %s';

    /** How long a worker may run past the end and the longest run's limit: its boot and its writing. */
    private const float SLACK = 60.0;

    public function __construct(
        private Project $project,
        private Shell $shell,
        private Invocation $invocation,
        private MemoryScan $scan,
        private MutantRun $run,
    ) {
    }

    /**
     * The runs, by their positions, in warm workers, none started once
     * `hrtime` reaches the end.
     *
     * @param array<int, PreparedRun> $runs by position
     */
    public function judged(MutationRequest $request, int|float $end, array $runs): Forked
    {
        if ($runs === []) {
            return Forked::nothing();
        }

        $workplace = Workplace::at($this->project->own(sprintf(self::WORKPLACE, getmypid(), hrtime(as_number: true))));
        $forked = $workplace->opened($this->jobOf($end, array_values($runs)))
            ? $this->worked($workplace, $request, $this->deadlineOf($end, $runs), $runs)
            : Forked::nothing();
        $workplace->removed();

        return $forked;
    }

    /** @param array<int, PreparedRun> $runs by position */
    private function worked(
        Workplace $workplace,
        MutationRequest $request,
        Seconds|Unlimited $deadline,
        array $runs,
    ): Forked {
        $slots = WorkerSlots::of($request->pool()->processes(), strval(getmypid()));
        $commands = [];

        foreach (array_keys([...$slots]) as $place) {
            $worker = $this->invocation->worker($workplace, $place, $request->withheld());
            $commands[] = $this->scan->onto($worker->within($deadline));
        }

        $ends = $this->shell->sideBySide($slots, Unlimited::time(), ...$commands);
        $judged = [];
        $evidence = Evidences::none();
        $positions = array_keys($runs);

        foreach (array_values($runs) as $at => $run) {
            $ran = End::ranIn($workplace, $at);

            if ($ran instanceof Ran) {
                $mutant = $this->run->finished($run, $ran);
                $judged[$positions[$at]] = $mutant;
                $evidence = $evidence->with($mutant->id(), $this->run->evidenced($run, $ran, $mutant));
            }
        }

        $warnings = $this->refusals($workplace, $ends);
        $this->kept($warnings);

        return Forked::of($judged, $warnings, $evidence);
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
            $this->project->config(),
            array_values(array_unique($mutated)),
            $end === PHP_INT_MAX ? NotGiven::value() : microtime(as_float: true) + $this->secondsTo($end),
            $warm,
        );
    }

    /** A prepared run as a worker's child runs it: PHPUnit's own command line, from its script on. */
    private function warmRunOf(PreparedRun $run): WarmRun
    {
        $arguments = $run->command()->arguments();
        $script = array_search($this->project->phpunit(), $arguments, strict: true);
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
     * @param array<int, PreparedRun> $runs
     */
    private function deadlineOf(int|float $end, array $runs): Seconds|Unlimited
    {
        $longest = 0.0;

        foreach ($runs as $run) {
            $longest = max($longest, $run->limit()->seconds());
        }

        return $end === PHP_INT_MAX
            ? Unlimited::time()
            : Seconds::of(max(0.0, $this->secondsTo($end)) + $longest + self::SLACK);
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

        return Warnings::of(...array_map(Warning::that(...), array_values($reasons)));
    }
}

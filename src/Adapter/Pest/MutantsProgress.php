<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_find;
use function count;
use function is_array;
use function min;

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamProgress;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;

use function sprintf;

/**
 * How far a Pest mutation run has come, as the results file the plugin
 * writes says, for the gate's log: how many of the mutants Pest planned it
 * has judged, those its split streams decided so far among them, and what
 * it does now: the pass its streams are in and how many of them still run,
 * or how many mutants are left for their own runs and the one it is
 * running. Every look is the watch's it wraps, such as the hand-off to
 * static analysis.
 *
 * @internal
 */
final readonly class MutantsProgress implements ProcessWatch
{
    private const string JUDGED = '%d of %d mutants judged';

    private const string STREAMING = '%s, %s';

    private const string RUNNING = '%s, own runs: %d left, running %s:%d (%s)';

    public function __construct(private string $results, private ProcessWatch $watch)
    {
    }

    public function look(): void
    {
        $this->watch->look();
    }

    public function progress(): string
    {
        $records = Records::in($this->results);

        if (! $records instanceof Records) {
            return $this->watch->progress();
        }

        $planned = array_filter($records->planned(), static fn(PlannedMutant $mutant): bool => ! $mutant->isTwin());
        $unjudged = array_filter(
            $planned,
            static fn(PlannedMutant $mutant): bool => $records->statusOf($mutant) === PestStatus::None,
        );
        $first = array_find(
            $unjudged,
            static fn(PlannedMutant $mutant): bool => is_array($records->runOf($mutant)->arguments()),
        );
        $streams = StreamProgress::seen($this->results);
        $left = count($unjudged) - min(count($unjudged), $streams->decided);
        $judged = sprintf(self::JUDGED, count($planned) - $left, count($planned));

        return match (true) {
            $streams->areStreaming() => sprintf(self::STREAMING, $judged, $streams->phase()),
            $first instanceof PlannedMutant => sprintf(
                self::RUNNING,
                $judged,
                $left,
                $first->file()->value(),
                $first->start()->number(),
                $first->mutator(),
            ),
            default => $judged,
        };
    }
}

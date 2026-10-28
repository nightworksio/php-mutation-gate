<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_values;
use function count;
use function is_array;

use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;

use function sprintf;

/**
 * How far a Pest mutation run has come, as the results file the plugin
 * writes says, for the gate's log: how many of the mutants Pest planned it
 * has judged, and one it is running. Every look is the watch's it wraps,
 * such as the hand-off to static analysis.
 */
final readonly class MutantsProgress implements ProcessWatch
{
    private const string JUDGED = '%d of %d mutants judged';

    private const string RUNNING = '%s, running %s:%d (%s)';

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

        $planned = array_values(array_filter(
            $records->planned(),
            static fn(PlannedMutant $mutant): bool => ! $mutant->isTwin(),
        ));
        $unjudged = array_values(array_filter(
            $planned,
            static fn(PlannedMutant $mutant): bool => $records->statusOf($mutant) === PestStatus::None,
        ));
        $running = array_values(array_filter(
            $unjudged,
            static fn(PlannedMutant $mutant): bool => is_array($records->runOf($mutant)->arguments()),
        ));
        $judged = sprintf(self::JUDGED, count($planned) - count($unjudged), count($planned));

        if ($running === []) {
            return $judged;
        }

        $first = $running[0];

        return sprintf(self::RUNNING, $judged, $first->file()->value(), $first->start()->number(), $first->mutator());
    }
}

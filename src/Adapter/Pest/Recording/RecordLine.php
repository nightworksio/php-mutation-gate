<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function json_encode;

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/**
 * One line of the results file, as the plugin writes it and the adapter reads
 * it: a JSON object naming its event, and the fields that event carries.
 */
final readonly class RecordLine
{
    /** How a line is written: a duration of whole seconds stays a float, so the adapter reads it as one. */
    public const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    public static function planned(PlannedMutant $mutant): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Planned->value,
            RecordField::Id->value => $mutant->id(),
            RecordField::File->value => $mutant->file()->value(),
            RecordField::Start->value => $mutant->start()->number(),
            RecordField::End->value => $mutant->end()->number(),
            RecordField::Mutator->value => $mutant->mutator(),
            RecordField::Diff->value => $mutant->diff(),
            RecordField::Mutated->value => $mutant->mutated()->value(),
        ]);
    }

    /** How many mutants Pest made, and the opening run's seconds, where Pest measured them. */
    public static function made(int $count, Seconds|Unmeasured $opening): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Made->value,
            RecordField::Count->value => $count,
            ...($opening instanceof Seconds ? [RecordField::Opening->value => $opening->seconds()] : []),
        ]);
    }

    /**
     * A mutant's status; one Pest has that the plugin does not know is
     * written as Pest names it, for the adapter to refuse.
     */
    public static function outcome(string $id, PestStatus|string $status): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Outcome->value,
            RecordField::Id->value => $id,
            RecordField::Status->value => $status instanceof PestStatus ? $status->value : $status,
        ]);
    }

    /** A mutant's final status and duration; a status the plugin does not know is written as Pest names it. */
    public static function finished(string $id, PestStatus|string $status, float $duration): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Finished->value,
            RecordField::Id->value => $id,
            RecordField::Status->value => $status instanceof PestStatus ? $status->value : $status,
            RecordField::Duration->value => $duration,
        ]);
    }

    /** A test that failed in the own process of the mutant Pest serves this mutated copy for. */
    public static function killed(string $mutated, string $test): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Killed->value,
            RecordField::Mutated->value => $mutated,
            RecordField::Test->value => $test,
        ]);
    }

    public static function end(): string
    {
        return self::line([RecordField::Event->value => RecordEvent::End->value]);
    }

    /**
     * A line of JSON, or a failure, so that a record JSON cannot hold, such
     * as a duration that is not a number, is never written as an empty line.
     *
     * @param array<string, string|int|float> $fields
     */
    private static function line(array $fields): string
    {
        return sprintf("%s\n", json_encode($fields, self::FLAGS | JSON_THROW_ON_ERROR));
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Recording;

use function json_encode;

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
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

    /** A test that failed in the own process of the mutant Pest serves this mutated copy for, and where it stood. */
    public static function killed(string $mutated, string $test, Placed $placed): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Killed->value,
            RecordField::Mutated->value => $mutated,
            RecordField::Test->value => $test,
            ...$placed->fields(),
        ]);
    }

    /** A test that errored in the own process of the mutant Pest serves this mutated copy for, and where it stood. */
    public static function errored(string $mutated, string $test, Placed $placed): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Errored->value,
            RecordField::Mutated->value => $mutated,
            RecordField::Test->value => $test,
            ...$placed->fields(),
        ]);
    }

    /** @param list<string> $files the test files a mutant's own run loads, by their paths on disk */
    public static function narrowed(string $mutated, array $files): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Narrowed->value,
            RecordField::Mutated->value => $mutated,
            RecordField::Files->value => $files,
        ]);
    }

    /** The seconds a patched run allowed the own process of the mutant Pest serves this mutated copy for. */
    public static function limited(string $mutated, float $seconds): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Limited->value,
            RecordField::Mutated->value => $mutated,
            RecordField::Seconds->value => $seconds,
        ]);
    }

    /** That the own run of the mutant with this mutated copy was stopped where no test finished for this long. */
    public static function silent(string $mutated, float $seconds): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Silent->value,
            RecordField::Mutated->value => $mutated,
            RecordField::Seconds->value => $seconds,
        ]);
    }

    /**
     * That the own process of the mutant Pest serves this mutated copy for had
     * loaded the original file before the mutant was in its place.
     */
    public static function preloaded(string $mutated): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Preloaded->value,
            RecordField::Mutated->value => $mutated,
        ]);
    }

    /** How many tests the own process of the mutant Pest serves this mutated copy for ran. */
    public static function ran(string $mutated, int $tests): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Ran->value,
            RecordField::Mutated->value => $mutated,
            RecordField::Count->value => $tests,
        ]);
    }

    /** The memory limit the own process of the mutant Pest serves this mutated copy for ran out of. */
    public static function exhausted(string $mutated, MemoryCap $limit): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Exhausted->value,
            RecordField::Mutated->value => $mutated,
            RecordField::Bytes->value => $limit->bytes(),
        ]);
    }

    /**
     * That PHP recorded a fatal error in the own process of the mutant Pest
     * serves this mutated copy for, as the error log it kept says.
     */
    public static function fatal(string $mutated): string
    {
        return self::line([
            RecordField::Event->value => RecordEvent::Fatal->value,
            RecordField::Mutated->value => $mutated,
        ]);
    }

    /**
     * How the own process of the mutant Pest serves this mutated copy for
     * ended, as the parent process saw it: its code and whether a signal
     * ended it, where it could tell, and never what it printed, which this
     * file, kept in the workspace a CI may upload, holds unscreened.
     */
    public static function ended(string $mutated, Ended $ended): string
    {
        $code = $ended->code();
        $signalled = $ended->signalled();

        return self::line([
            RecordField::Event->value => RecordEvent::Ended->value,
            RecordField::Mutated->value => $mutated,
            ...($code instanceof NotGiven ? [] : [RecordField::Code->value => $code]),
            ...($signalled instanceof NotGiven ? [] : [RecordField::Signalled->value => $signalled]),
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
     * @param array<string, string|int|float|bool|list<string>> $fields
     */
    private static function line(array $fields): string
    {
        return sprintf("%s\n", json_encode($fields, self::FLAGS | JSON_THROW_ON_ERROR));
    }
}

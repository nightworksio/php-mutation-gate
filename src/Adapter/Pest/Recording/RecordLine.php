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

    public const string EVENT = 'event';

    public const string ID = 'id';

    public const string FILE = 'file';

    public const string START = 'start';

    public const string END = 'end';

    public const string MUTATOR = 'mutator';

    public const string DIFF = 'diff';

    public const string MUTATED = 'mutated';

    public const string COUNT = 'count';

    public const string OPENING = 'opening';

    public const string STATUS = 'status';

    public const string DURATION = 'duration';

    public const string TEST = 'test';

    public static function planned(PlannedMutant $mutant): string
    {
        return self::line([
            self::EVENT => RecordEvent::Planned->value,
            self::ID => $mutant->id(),
            self::FILE => $mutant->file()->value(),
            self::START => $mutant->start()->number(),
            self::END => $mutant->end()->number(),
            self::MUTATOR => $mutant->mutator(),
            self::DIFF => $mutant->diff(),
            self::MUTATED => $mutant->mutated()->value(),
        ]);
    }

    /** How many mutants Pest made, and the opening run's seconds, where Pest measured them. */
    public static function made(int $count, Seconds|Unmeasured $opening): string
    {
        return self::line([
            self::EVENT => RecordEvent::Made->value,
            self::COUNT => $count,
            ...($opening instanceof Seconds ? [self::OPENING => $opening->seconds()] : []),
        ]);
    }

    public static function outcome(string $id, PestStatus $status): string
    {
        return self::line([
            self::EVENT => RecordEvent::Outcome->value,
            self::ID => $id,
            self::STATUS => $status->value,
        ]);
    }

    public static function finished(string $id, PestStatus $status, float $duration): string
    {
        return self::line([
            self::EVENT => RecordEvent::Finished->value,
            self::ID => $id,
            self::STATUS => $status->value,
            self::DURATION => $duration,
        ]);
    }

    /** A test that failed in the own process of the mutant Pest serves this mutated copy for. */
    public static function killed(string $mutated, string $test): string
    {
        return self::line([self::EVENT => RecordEvent::Killed->value, self::MUTATED => $mutated, self::TEST => $test]);
    }

    public static function end(): string
    {
        return self::line([self::EVENT => RecordEvent::End->value]);
    }

    /** @param array<string, string|int|float> $fields */
    private static function line(array $fields): string
    {
        return sprintf("%s\n", json_encode($fields, self::FLAGS));
    }
}

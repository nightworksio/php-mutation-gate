<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function is_array;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Test\TestPart;

use function sprintf;
use function sscanf;

/**
 * The part of the test files whose tests judge a run's mutants, as the gate
 * tells Pest's own process (ADR-0117): its number, then how many parts there
 * are; nothing of every test file.
 *
 * @internal
 */
final readonly class TestPartVariable
{
    /** A part of the test files, as the gate tells it: its number, then how many parts there are. */
    private const string PART = '%d/%d';

    /** @return array<string, string> */
    public static function told(TestPart $part): array
    {
        return $part->isWhole()
            ? []
            : [GateVariable::SplitPart->value => sprintf(self::PART, $part->number(), $part->parts())];
    }

    /** The part a variable names, as the gate writes it; every test file where it names none there is. */
    public static function read(string|false $told): TestPart
    {
        $read = is_string($told) ? sscanf($told, self::PART) : null;
        [$number, $parts] = is_array($read) ? $read + [0, 0] : [0, 0];

        return is_int($number) && is_int($parts) && $number >= 1 && $number <= $parts
            ? TestPart::of($number, $parts)
            : TestPart::whole();
    }
}

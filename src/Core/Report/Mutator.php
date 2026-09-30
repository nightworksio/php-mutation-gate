<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function count;
use function explode;

/** A mutator's name as a report prints it: its last segment, `LessThan` for Pest's full class name. */
final readonly class Mutator
{
    public static function short(string $mutator): string
    {
        $segments = explode('\\', $mutator);

        return $segments[count($segments) - 1];
    }
}

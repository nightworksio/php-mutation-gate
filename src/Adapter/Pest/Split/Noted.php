<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use const FILE_APPEND;

use function file_put_contents;
use function getenv;
use function is_string;

use const LOCK_EX;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use Pest\Mutate\MutationTest;

/**
 * What Pest's own process notes of a mutant before or instead of its own
 * run (ADR-0117), in the mutant's killer file, which it takes as that
 * mutant's records once the mutant ends (see KillerFile); nothing where the
 * gate records no run.
 *
 * @internal
 */
final readonly class Noted
{
    /** Notes these lines, each ended, of the mutant. */
    public static function of(MutationTest $test, string $lines): void
    {
        $results = getenv(GateVariable::Results->value);

        if (is_string($results) && $results !== '') {
            file_put_contents(
                KillerFile::beside($results, $test->mutation->modifiedSourcePath),
                $lines,
                FILE_APPEND | LOCK_EX,
            );
        }
    }
}

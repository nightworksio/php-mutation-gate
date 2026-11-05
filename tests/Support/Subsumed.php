<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

use function sprintf;

/** Mutants a test of subsumption makes: each by its file, line and diff, its status, and the tests that killed it. */
final class Subsumed
{
    /** A mutant of a line of a file, changing it as this diff says, with this status and these killers. */
    public static function mutant(
        string $file,
        int $line,
        string $diff,
        MutantStatus $status = MutantStatus::Killed,
        string ...$killers,
    ): Mutant {
        $path = Path::of($file);

        return Mutant::of(
            MutantId::hash($path, 'Plus', $diff, 0),
            sprintf('native-%s-%d', $file, $line),
            Location::of($path, Line::of($line), Line::of($line)),
            Mutation::of('Plus', MutatorFamily::Arithmetic, $diff),
            $status,
            Unmeasured::duration(),
        )->killedBy(TestIds::of(...array_map(static fn(string $test): TestId => TestId::of($test), $killers)));
    }

    /** A killed mutant of line 1 of `src/Money.php`, told apart by its diff, killed by these tests. */
    public static function killed(string $diff, string ...$killers): Mutant
    {
        return self::mutant('src/Money.php', 1, $diff, MutantStatus::Killed, ...$killers);
    }

    /** The diff of a line changed from one text to another. */
    public static function diff(string $from, string $to): string
    {
        return sprintf("-%s\n+%s\n", $from, $to);
    }
}

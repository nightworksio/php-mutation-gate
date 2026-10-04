<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

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
use NightWorksIO\MutationGate\Core\Triage\Outcomes;
use Pest\Mutate\Mutators\Number\IncrementInteger;

use function sprintf;

/** Mutants of `src/Money.php` as repeated runs give them, and what a triage makes of one. */
final class Varying
{
    public const string MUTATOR = IncrementInteger::class;

    /** The mutant raising the integer `src/Money.php` returns on this line, given this status by a run, killed by these tests. */
    public static function mutant(int $line, MutantStatus $status, string ...$killers): Mutant
    {
        $file = Path::of('src/Money.php');
        $diff = sprintf("-        return %d;\n+        return %d;\n", $line, $line + 1);
        $tests = TestIds::none();

        foreach ($killers as $killer) {
            $tests = $tests->with(TestId::of($killer));
        }

        return Mutant::of(
            MutantId::hash($file, self::MUTATOR, $diff, 1),
            sprintf('native-%d', $line),
            Location::of($file, Line::of($line), Line::of($line)),
            Mutation::of(self::MUTATOR, MutatorFamily::Literal, $diff),
            $status,
            Unmeasured::duration(),
        )->killedBy($tests);
    }

    /**
     * What each run gave a mutant, grouped: the status or `not made`, the
     * runs, and the ids of the tests that killed it in them.
     *
     * @return list<array{string, list<int>, list<string>}>
     */
    public static function grouped(Outcomes $outcomes): array
    {
        $grouped = [];

        foreach ($outcomes->grouped() as [$outcome, $runs, $killers]) {
            $ids = [];

            foreach ($killers as $killer) {
                $ids[] = $killer->value();
            }

            $grouped[] = [$outcome instanceof Mutant ? $outcome->status()->value : 'not made', $runs, $ids];
        }

        return $grouped;
    }
}

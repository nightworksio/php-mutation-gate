<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function iterator_to_array;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;

/** Judged mutants a test names by their native id, judgement, file and line. */
final class Judged
{
    public static function mutant(
        string $native,
        MutantJudgement $judgement,
        string $file = 'src/Money.php',
        int $line = 1,
    ): JudgedMutant {
        $path = Path::of($file);

        return JudgedMutant::of(Mutant::of(
            MutantId::hash($path, 'LessThan', $native, 0),
            $native,
            Location::of($path, Line::of($line), Line::of($line)),
            Mutation::of('LessThan', MutatorFamily::Boundary, ''),
            MutantStatus::Killed,
            Unmeasured::duration(),
        ), $judgement);
    }

    /** One mutant for each judgement given, named by its position. */
    public static function mutants(MutantJudgement ...$judgements): JudgedMutants
    {
        $mutants = JudgedMutants::none();

        foreach ($judgements as $position => $judgement) {
            $mutants = $mutants->with(self::mutant((string) $position, $judgement));
        }

        return $mutants;
    }

    /** @return list<string> the native ids of the mutants reported in full, in order */
    public static function natives(Survivors|JudgedMutants $mutants): array
    {
        return array_map(
            static fn(JudgedMutant $mutant): string => $mutant->mutant()->nativeId(),
            $mutants instanceof JudgedMutants ? self::listed($mutants) : iterator_to_array($mutants, preserve_keys: false),
        );
    }

    /** @return list<JudgedMutant> the mutants reported in full, in order, leaving out any kill a ledger proved */
    public static function listed(JudgedMutants $mutants): array
    {
        $listed = [];

        foreach ($mutants as $mutant) {
            if ($mutant instanceof JudgedMutant) {
                $listed[] = $mutant;
            }
        }

        return $listed;
    }
}

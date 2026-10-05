<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

/** The last run's survivors re-checked, as the reports' tests show them. */
final readonly class Rechecks
{
    /** One still surviving, one killed now and one gone, in that order. */
    public static function mixed(): Rechecked
    {
        $survivor = Verdicts::survivor();

        return Rechecked::of(
            Uncovered::Count,
            Recheck::found($survivor, $survivor),
            Recheck::found(self::judged('src/Price.php:9', MutantJudgement::Survived), self::judged('src/Price.php:9', MutantJudgement::Killed)),
            Recheck::gone(self::judged('src/Cart.php:4', MutantJudgement::Survived)),
        );
    }

    /** A mutant judged this way, at this place. */
    public static function judged(string $at, MutantJudgement $judgement): JudgedMutant
    {
        return JudgedMutant::of(Verdicts::mutant($at, 'Plus', MutatorFamily::None, ''), $judgement);
    }
}

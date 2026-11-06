<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\AnalyserHistories;
use NightWorksIO\MutationGate\Core\Cost\MutatorTallies;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Proof\Learned;
use NightWorksIO\MutationGate\Core\Proof\LimitHistory;
use NightWorksIO\MutationGate\Core\Pruning\Survival;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Tests\Support\Subsumed;

it('takes what a run learned in place of what it held of that kind alone, and reads its own before another\'s', function (): void {
    $by = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $of = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');
    $tax = Subsumed::mutant('src/Tax.php', 1, Subsumed::diff('a', 'b'), killers: 'T1');
    $taxOf = Subsumed::mutant('src/Tax.php', 1, Subsumed::diff('a', 'c'), killers: 'T1');
    $limits = LimitHistory::none()->afterKills(Multiple::of(2.0));
    $learned = Learned::none()->with(Subsumption::learned($by, $of))->with($limits);
    $other = Learned::none()->with(Subsumption::learned($tax, $taxOf, $by));

    expect([$learned->subsumption(), $learned->limits(), $learned->killers(), $learned->analysers(), $learned->survival(), $learned->tallies()])
        ->toEqual([Subsumption::learned($by, $of), $limits, KillHistory::none(), AnalyserHistories::none(), Survival::none(), MutatorTallies::none()])
        ->and($learned->and($other)->subsumption())->toEqual(Subsumption::learned($by, $of)->and(Subsumption::learned($tax, $taxOf)))
        ->and($learned->keepingKillersIn(Paths::none())->subsumption())
        ->toEqual(Subsumption::learned($by, $of));
});

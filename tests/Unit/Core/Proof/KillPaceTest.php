<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Proof\KillPace;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

$paced = static fn(int $line, MutantStatus $status, Seconds|Unmeasured $took): Mutant => Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'Plus', sprintf('-%d', $line), 0),
    sprintf('%d', $line),
    Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
    Mutation::of('Plus', MutatorFamily::Arithmetic, sprintf('-%d', $line)),
    $status,
    $took,
);

it('paces each mutant a test killed by its run\'s time over its covering tests\' own time, and no other', function () use ($paced): void {
    $map = CoverageMap::of()
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('MoneyTest::adds'), TestId::of('MoneyTest::rounds'))
        ->covered(Path::of('src/Money.php'), Line::of(5), TestId::of('MoneyTest::untimed'))
        ->covered(Path::of('src/Money.php'), Line::of(7), TestId::of('MoneyTest::instant'))
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'))
        ->timed(TestId::of('MoneyTest::adds'), Seconds::of(0.5))
        ->timed(TestId::of('MoneyTest::rounds'), Seconds::of(1.5))
        ->timed(TestId::of('MoneyTest::instant'), Seconds::of(0.0));

    expect(KillPace::ofAll(Mutants::of(
        $paced(3, MutantStatus::Killed, Seconds::of(5.0)),
        $paced(3, MutantStatus::KilledByStaticAnalysis, Seconds::of(5.0)),
        $paced(3, MutantStatus::TimedOut, Seconds::of(30.0)),
        $paced(3, MutantStatus::Killed, Unmeasured::duration()),
        $paced(5, MutantStatus::Killed, Seconds::of(5.0)),
        $paced(7, MutantStatus::Killed, Seconds::of(5.0)),
        $paced(9, MutantStatus::Killed, Seconds::of(5.0)),
        $paced(11, MutantStatus::Killed, Seconds::of(2.0)),
    ), $map))->toEqual([Multiple::of(2.5), Multiple::of(4.0)]);
});

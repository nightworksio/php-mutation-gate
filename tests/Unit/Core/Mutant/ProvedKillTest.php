<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

it('is a kill a ledger proved: its id, its unit and line, its mutator and its killers, and nothing a ledger does not keep', function (): void {
    $id = MutantId::hash(Path::of('src/Money.php'), 'Plus', "-+\n+-", 0);
    $killers = TestIds::of(TestId::of('MoneyTest::adds'));
    $kill = ProvedKill::of($id, Path::of('src/Money.php'), Line::of(44), 'Plus', $killers);

    expect($kill->id())->toBe($id)
        ->and($kill->location())->toEqual(Location::of(Path::of('src/Money.php'), Line::of(44), Unreported::line()))
        ->and($kill->mutator())->toBe('Plus')
        ->and($kill->status())->toBe(MutantStatus::Killed)
        ->and($kill->killers())->toBe($killers)
        ->and($kill->reason())->toEqual(Unreported::reason())
        ->and($kill->duration())->toEqual(Unmeasured::duration())
        ->and($kill->limit())->toEqual(Unmeasured::duration());
});

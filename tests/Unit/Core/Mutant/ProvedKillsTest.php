<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Test\TestIds;

it('holds the kills a ledger proved, in the order it keeps them', function (): void {
    $kill = static fn(int $line): ProvedKill => ProvedKill::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', "-+\n+-", $line),
        Path::of('src/Money.php'),
        Line::of($line),
        'Plus',
        TestIds::none(),
    );
    $kills = ProvedKills::of($kill(9), $kill(4));

    expect(ProvedKills::none())->toHaveCount(0)
        ->and($kills)->toHaveCount(2)
        ->and([...$kills])->toEqual([$kill(9), $kill(4)]);
});

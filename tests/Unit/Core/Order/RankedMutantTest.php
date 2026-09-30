<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\RankedMutant;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Test\TestId;

it('holds a mutant and its ranking', function (): void {
    $mutant = MutantId::hash(Path::of('src/Money.php'), 'Plus', "-a\n+b", 0);
    $ranking = Ranking::of(Kills::of(TestId::of('a'), 1));
    $ranked = RankedMutant::of($mutant, $ranking);

    expect($ranked->mutant())->toBe($mutant)
        ->and($ranked->ranking())->toBe($ranking);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;

it('is a unit and where its result came from', function (): void {
    $unit = Unit::file(Path::of('src/Money.php'));
    $judged = JudgedUnit::of($unit, Origin::Carried);

    expect($judged->unit())->toBe($unit)
        ->and($judged->origin())->toBe(Origin::Carried);
});

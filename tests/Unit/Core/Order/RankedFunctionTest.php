<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\RankedFunction;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Test\TestId;

it('holds a function and its ranking', function (): void {
    $function = Enclosing::named(Path::of('src/Money.php'), 'add');
    $ranking = Ranking::of(Kills::of(TestId::of('a'), 1));
    $ranked = RankedFunction::of($function, $ranking);

    expect($ranked->function())->toBe($function)
        ->and($ranked->ranking())->toBe($ranking);
});

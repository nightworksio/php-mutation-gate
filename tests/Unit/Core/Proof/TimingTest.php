<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('is what mutating a unit took', function (): void {
    $timing = Timing::of(Path::of('src/Money.php'), Seconds::of(12.4));

    expect($timing->unit()->value())->toBe('src/Money.php')
        ->and($timing->seconds())->toEqual(Seconds::of(12.4));
});

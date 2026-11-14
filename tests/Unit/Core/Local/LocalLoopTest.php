<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\LocalWork;
use NightWorksIO\MutationGate\Core\Config\SpeedUps;
use NightWorksIO\MutationGate\Core\Local\LocalLoop;

it('places a changed test file by the static graph only in a quick round that records lines and counts site hits', function (): void {
    $loop = LocalLoop::of(LocalWork::of());

    expect($loop->placesTestFiles())->toBeFalse()
        ->and($loop->quickly()->placesTestFiles())->toBeTrue()
        ->and(LocalLoop::of(LocalWork::of(speedUps: SpeedUps::of(siteHits: false)))->quickly()->placesTestFiles())->toBeFalse()
        ->and(LocalLoop::of(LocalWork::of(speedUps: SpeedUps::of(recordLines: false)))->quickly()->placesTestFiles())->toBeFalse();
});

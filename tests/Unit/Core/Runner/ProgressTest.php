<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Progress;
use NightWorksIO\MutationGate\Core\Time\Seconds;

$silence = Seconds::of(2.0);

it('never stalls before what shows it first grows, however long that takes', function () use ($silence): void {
    $progress = Progress::under($silence)->read(0, 100.0);

    expect($progress->hasStalled(1000.0))->toBeFalse();
});

it('stalls once what shows it has not grown for the silence limit since it last grew, and grows again where it is larger', function () use ($silence): void {
    $grown = Progress::under($silence)->read(10, 1.0);
    $same = $grown->read(10, 2.5);
    $again = $same->read(20, 2.9);

    expect([$grown->hasStalled(3.0), $grown->hasStalled(3.1)])->toBe([false, true])
        ->and($same->hasStalled(3.1))->toBeTrue()
        ->and([$again->hasStalled(4.8), $again->hasStalled(5.0)])->toBe([false, true]);
});

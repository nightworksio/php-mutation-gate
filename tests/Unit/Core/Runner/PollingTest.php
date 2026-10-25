<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('looks at a running process a hundred times a second', function (): void {
    expect(Polling::interval())->toEqual(Seconds::of(0.01));
});

it('waits for a condition an interval at a time, as many intervals as fit, and no longer once it holds', function (): void {
    $pauses = new ArrayObject();
    $pause = static function (int $microseconds) use ($pauses): void {
        $pauses->append($microseconds);
    };
    Polling::wait(Seconds::of(0.045), static fn(): bool => false, $pause);
    $never = $pauses->getArrayCopy();
    $pauses->exchangeArray([]);
    Polling::wait(Seconds::of(1.0), static fn(): bool => $pauses->count() === 2, $pause);

    expect($never)->toBe([10_000, 10_000, 10_000, 10_000])
        ->and($pauses->getArrayCopy())->toBe([10_000, 10_000]);
});

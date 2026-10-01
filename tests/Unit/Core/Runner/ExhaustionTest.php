<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;

it('reads the limit PHP says a process ran out of, in bytes, wherever the text holds its fatal error', function (): void {
    $fatal = 'PHP Fatal error:  Allowed memory size of 67108864 bytes exhausted (tried to allocate 20480 bytes) in /a.php';

    expect(Exhaustion::in($fatal))->toEqual(MemoryCap::of(64, MemoryUnit::Megabytes))
        ->and(Exhaustion::in("Tests: 1 failed\nAllowed memory size of 1024 bytes exhausted"))
        ->toEqual(MemoryCap::of(1, MemoryUnit::Kilobytes));
});

it('reads no limit from text without PHP\'s fatal error, or with a limit PHP never names', function (string $text): void {
    expect(Exhaustion::in($text))->toEqual(NotGiven::value());
})->with([
    'another fatal' => ['PHP Fatal error:  Maximum execution time of 30 seconds exceeded'],
    'none' => [''],
    'no bytes' => ['Allowed memory size of 0 bytes exhausted'],
    'more than PHP counts' => ['Allowed memory size of 99999999999999999999 bytes exhausted'],
]);

it('tells the gate\'s own cap, to the byte, from a limit a project set itself', function (
    MemoryCap|NotGiven $limit,
    MemoryCap $cap,
    bool $isOf,
): void {
    expect(Exhaustion::isOf($limit, $cap))->toBe($isOf);
})->with([
    'the cap' => [MemoryCap::of(67108864, MemoryUnit::Bytes), MemoryCap::of(64, MemoryUnit::Megabytes), true],
    'a byte more' => [MemoryCap::of(67108865, MemoryUnit::Bytes), MemoryCap::of(64, MemoryUnit::Megabytes), false],
    'a project\'s own' => [MemoryCap::of(128, MemoryUnit::Megabytes), MemoryCap::of(64, MemoryUnit::Megabytes), false],
    'no limit read' => [NotGiven::value(), MemoryCap::of(64, MemoryUnit::Megabytes), false],
    'no cap' => [MemoryCap::of(1, MemoryUnit::Bytes), MemoryCap::none(), false],
]);

it('says what to do where the suite does not fit under the cap', function (): void {
    expect(Exhaustion::ADVICE)->toBe('Raise runner.memory; doctor --measure says what the suite needs.');
});

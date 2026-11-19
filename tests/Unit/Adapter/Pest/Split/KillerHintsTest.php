<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\KillerHints;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\GateEnvironment;
use NightWorksIO\MutationGate\Tests\Support\Orders;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

beforeEach(function (): void {
    GateEnvironment::clear();
});

afterEach(function (): void {
    GateEnvironment::restore();
    Scratch::sweep();
});

it('names the likeliest killer the history names among the tests that cover a mutant', function (): void {
    [, $suite, $order] = Orders::project(mapped: true);
    $hints = KillerHints::of($suite, $order, Root::of(dirname($order, 2)));

    expect([
        $hints->killerOf('n1', [Orders::ADDS, Orders::TWICE]),
        $hints->killerOf('n1', [Orders::ADDS]),
        $hints->killerOf('n1', [Orders::ROWS]),
        $hints->killerOf('n2', [Orders::ADDS, Orders::TWICE]),
        $hints->killerOf('unmade', [Orders::ADDS]),
    ])->toBe([Orders::TWICE, Orders::ADDS, '', '', '']);
});

it('names no killer where the gate named no order directory', function (string|false $directory): void {
    [, $suite] = Orders::project(mapped: true);

    expect(KillerHints::of($suite, $directory, Root::of('/p'))->killerOf('n1', [Orders::ADDS, Orders::TWICE]))->toBe('')
        ->and(KillerHints::none()->killerOf('n1', [Orders::TWICE]))->toBe('');
})->with([false, '']);

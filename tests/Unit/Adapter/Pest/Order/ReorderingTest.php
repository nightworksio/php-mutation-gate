<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Order\Reordering;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Seed;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** An order directory holding an order for the mutated copy at /tmp/mutations/abc. */
function reorderingDirectory(): string
{
    $order = Scratch::directory();
    Scratch::write(Seed::directoryOf($order, '/tmp/mutations/abc'), Seed::HISTORY, '{}');

    return $order;
}

it('runs a mutant\'s tests in the order written for it, dropping every option that would contradict it', function (): void {
    $order = reorderingDirectory();
    $arguments = [
        'vendor/bin/pest', '--cache-directory', '/v/.temp', '--no-tia', '--cache-directory=/elsewhere',
        '--order-by', 'random', '--order-by=size', '--record-test-run-history', '--do-not-record-test-run-history',
        '--cache-result', '--do-not-cache-result', '--bail', '--filter=MoneySpec',
    ];

    expect(Reordering::of($arguments, $order, '/tmp/mutations/abc'))->toBe([
        'vendor/bin/pest', '--no-tia', '--bail', '--filter=MoneySpec',
        sprintf('--cache-directory=%s/abc', $order),
        '--record-test-run-history',
        '--order-by=defects,duration',
    ]);
});

it('leaves the arguments as they are where no order was written, or this is no mutant\'s process', function (): void {
    $order = reorderingDirectory();
    $arguments = ['vendor/bin/pest', '--cache-directory', '/v/.temp', '--bail'];

    expect(Reordering::of($arguments, $order, '/tmp/mutations/other'))->toBe($arguments)
        ->and(Reordering::of($arguments, $order, mutated: false))->toBe($arguments)
        ->and(Reordering::of($arguments, $order, ''))->toBe($arguments)
        ->and(Reordering::of($arguments, directory: false, mutated: '/tmp/mutations/abc'))->toBe($arguments)
        ->and(Reordering::of($arguments, '', '/tmp/mutations/abc'))->toBe($arguments);
});

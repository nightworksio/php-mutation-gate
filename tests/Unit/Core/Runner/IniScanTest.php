<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\IniScan;

/**
 * The environment of a process whose PHP_INI_SCAN_DIR holds this, or none where it is false.
 *
 * @return array<string, string>
 */
function scanningBefore(string|false $before): array
{
    return $before === false ? [] : [IniScan::VARIABLE => $before];
}

it('adds a directory after what PHP scanned, its own where the variable was unset and none but it where it was empty, keeping what it held', function (string|false $before, string $scanned, string $kept): void {
    expect(IniScan::adding('/off', scanningBefore($before)))->toBe([
        IniScan::VARIABLE => $scanned,
        IniScan::KEPT => $kept,
        IniScan::ADDED => '/off',
    ]);
})->with([
    'unset' => [false, ':/off', 'unset'],
    'empty' => ['', '/off', 'set:'],
    'set' => ['/etc/php.d', '/etc/php.d:/off', 'set:/etc/php.d'],
]);

it('gives a process started from one that added a directory what PHP scanned before, unset where it was unset, and adds once over what was added', function (string|false $before): void {
    $inherited = scanningBefore($before);
    $added = [...$inherited, ...IniScan::adding('/off', $inherited)];

    expect(IniScan::restored($added))->toBe([IniScan::VARIABLE => $before, IniScan::KEPT => false, IniScan::ADDED => false])
        ->and(IniScan::adding('/off', $added))->toBe(IniScan::adding('/off', $inherited));
})->with([
    'unset' => [false],
    'empty' => [''],
    'set' => ['/etc/php.d'],
]);

it('restores nothing where no run added a directory, and leaves the variable as it is where it no longer starts with what the run made of it', function (): void {
    $added = IniScan::adding('/off', [IniScan::VARIABLE => '/etc/php.d']);

    expect(IniScan::restored([IniScan::VARIABLE => '/etc/php.d']))->toBe([])
        ->and(IniScan::restored([...$added, IniScan::VARIABLE => '/elsewhere']))->toBe([IniScan::KEPT => false, IniScan::ADDED => false])
        ->and(IniScan::restored([...$added, IniScan::VARIABLE => '/etc/php.d:/offer']))->toBe([IniScan::KEPT => false, IniScan::ADDED => false]);
});

it('drops only the run\'s directory where another was added after it, keeping that one after what PHP scanned before', function (string|false $before, string $kept): void {
    $inherited = scanningBefore($before);
    $added = [...$inherited, ...IniScan::adding('/off', $inherited)];
    $capped = [...$added, IniScan::VARIABLE => sprintf('%s:/cap:/children', $added[IniScan::VARIABLE])];

    expect(IniScan::restored($capped))->toBe([IniScan::VARIABLE => $kept, IniScan::KEPT => false, IniScan::ADDED => false]);
})->with([
    'unset' => [false, ':/cap:/children'],
    'empty' => ['', '/cap:/children'],
    'set' => ['/etc/php.d', '/etc/php.d:/cap:/children'],
]);

it('drops only the run\'s directory where it was added after another, as a memory cap\'s', function (): void {
    $capped = [IniScan::VARIABLE => ':/cap'];
    $added = [...$capped, ...IniScan::adding('/off', $capped)];

    expect(IniScan::restored([...$added, IniScan::VARIABLE => sprintf('%s:/children', $added[IniScan::VARIABLE])]))
        ->toBe([IniScan::VARIABLE => ':/cap:/children', IniScan::KEPT => false, IniScan::ADDED => false]);
});

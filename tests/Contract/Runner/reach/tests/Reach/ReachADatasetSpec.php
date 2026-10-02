<?php

declare(strict_types=1);

dataset('reach amounts', function (): array {
    $GLOBALS['reachDataset'] = 6;

    return [[1]];
});

it('sets, through its dataset, the global another test file falls back from', function (int $amount): void {
    expect($amount)->toBe(1)
        ->and($GLOBALS['reachDataset'])->toBe(6);
})->with('reach amounts');

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\File\Path;

it('holds a line of a file and the tests that ran it, in order', function (): void {
    $line = CoveredLine::of(Path::of('src/Money.php'), 12, ...[3 => 'MoneyTest::adds', 7 => 'MoneyTest::subtracts']);

    expect($line->file())->toEqual(Path::of('src/Money.php'))
        ->and($line->line())->toBe(12)
        ->and(iterator_to_array($line, preserve_keys: true))->toBe(['MoneyTest::adds', 'MoneyTest::subtracts']);
});

it('holds the tests however they were passed', function (): void {
    expect(iterator_to_array(CoveredLine::of(Path::of('src/Money.php'), 12, first: 'MoneyTest::adds'), preserve_keys: true))
        ->toBe(['MoneyTest::adds']);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\ServedClasses;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Recorded;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ServedBox;

afterEach(function (): void {
    Scratch::sweep();
});

it('records the lines of a copy this process serves as its original\'s, joined to the original\'s own, and every other file\'s as they are', function (): void {
    $box = ServedBox::registered();

    try {
        ServedClasses::serve($box->file, $box->copy);
        $original = (string) realpath($box->file);
        $served = (string) realpath(sprintf('%s.served.php', $box->copy));

        expect(Recorded::atOriginals([$served => [9, 3], $original => [3, 5], '/project/src/Other.php' => [7]]))->toBe([
            $original => [3, 5, 9],
            '/project/src/Other.php' => [7],
        ])
            ->and(Recorded::atOriginals([$served => [4, 2]]))->toBe([$original => [2, 4]])
            ->and(Recorded::atOriginals([]))->toBe([]);
    } finally {
        ServedClasses::forget($box->file);
        $box->loader->unregister();
    }
});

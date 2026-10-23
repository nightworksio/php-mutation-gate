<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\Runner\Opcache;

it('reads whether the wrapper served the mutated file and whether opcache could have served an original', function (bool $served, bool $cached, string ...$lines): void {
    $guard = Guard::of(array_values($lines));

    expect([$guard->served(), $guard->cached()])->toBe([$served, $cached]);
})->with([
    'nothing said' => [false, false],
    'served' => [true, false, 'served'],
    'served in two processes, with opcache on' => [true, true, 'served', 'cached', 'served'],
    'cached alone' => [false, true, 'cached'],
]);

it('notes whether opcache could serve an original, which it reads back', function (): void {
    expect(Guard::noting(Opcache::ofCommandLine('1')))->toBe("cached\n")
        ->and(Guard::noting(Opcache::ofCommandLine('0')))->toBe("uncached\n")
        ->and(Guard::of(['uncached'])->cached())->toBeFalse()
        ->and(Guard::of(['served', 'uncached'])->served())->toBeTrue();
});

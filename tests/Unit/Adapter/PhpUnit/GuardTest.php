<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Guard;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads whether the wrapper served the mutated file and whether opcache could have served an original', function (string $lines, bool $served, bool $cached): void {
    $file = sprintf('%s/guard.txt', Scratch::directory());
    file_put_contents($file, $lines);
    $guard = Guard::in($file);

    expect([$guard->served(), $guard->cached()])->toBe([$served, $cached]);
})->with([
    'nothing said' => ['', false, false],
    'served' => ["served\n", true, false],
    'served in two processes, with opcache on' => ["served\ncached\nserved\n", true, true],
    'cached alone' => ["cached\n", false, true],
]);

it('reads nothing served where there is no guard file', function (): void {
    $guard = Guard::in(sprintf('%s/none.txt', Scratch::directory()));

    expect([$guard->served(), $guard->cached()])->toBe([false, false]);
});

it('notes in the guard file that opcache could serve an original, and only where it could and a file is named', function (): void {
    $file = sprintf('%s/guard.txt', Scratch::directory());
    Guard::noting($file, Opcache::ofCommandLine('0'));
    Guard::noting(file: false, opcache: Opcache::ofCommandLine('1'));
    Guard::noting('', Opcache::ofCommandLine('1'));
    $untouched = is_file($file);
    Guard::noting($file, Opcache::ofCommandLine('1'));

    expect($untouched)->toBeFalse()
        ->and(Guard::in($file)->cached())->toBeTrue();
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Attribute\Holds;
use NightWorksIO\MutationGate\Tests\Support\Holding;

// The canary a patched shard opens on (pest.patch, ADR-0004): quick, and in a
// file that rarely changes, since every proof key reads it.
it('holds the path a test spells', function (): void {
    expect(new Holds('src/Kernel.php')->path())->toBe('src/Kernel.php');
})->group('mutation-canary');

it('stands on a class, a method or a function, as often as a test needs', function (): void {
    $attribute = new ReflectionClass(Holds::class)->getAttributes(Attribute::class)[0]->newInstance();

    expect($attribute->flags)->toBe(
        Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE,
    );
});

it('is read from a test written with it', function (): void {
    $test = Holding::kernelAndHttp();
    $paths = [];

    foreach (new ReflectionClass($test)->getAttributes(Holds::class) as $attribute) {
        $paths[] = $attribute->newInstance()->path();
    }

    expect($paths)->toBe(['src/Kernel.php', 'src/Http']);
});

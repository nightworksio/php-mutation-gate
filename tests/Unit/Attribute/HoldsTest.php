<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Attribute\Holds;

it('holds the path a test spells', function (): void {
    expect(new Holds('src/Kernel.php')->path())->toBe('src/Kernel.php');
});

it('stands on a class, a method or a function, as often as a test needs', function (): void {
    $attribute = new ReflectionClass(Holds::class)->getAttributes(Attribute::class)[0]->newInstance();

    expect($attribute->flags)->toBe(
        Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE,
    );
});

it('is read from a test written with it', function (): void {
    $test = new #[Holds('src/Kernel.php'), Holds('src/Http')] class {};
    $paths = [];

    foreach (new ReflectionClass($test)->getAttributes(Holds::class) as $attribute) {
        $paths[] = $attribute->newInstance()->path();
    }

    expect($paths)->toBe(['src/Kernel.php', 'src/Http']);
});

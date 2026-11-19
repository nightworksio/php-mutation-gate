<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\TestFilePlaces;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

it('finds the test files Pest declares a class for or that are named after a name, each once, in the order listed', function (): void {
    $places = TestFilePlaces::of(
        Paths::of(Path::of('tests/Unit/MoneyTest.php'), Path::of('tests/RateTest.php'), Path::of('tests/Feature/MoneyTest.php')),
        static fn(Path $file): string => sprintf('P\%s', str_replace(['/', '.php'], ['\\', ''], ucfirst($file->value()))),
    );
    $listed = static fn(string ...$names): array => array_map(static fn(Path $path): string => $path->value(), $places->standingFor(array_values($names)));

    expect($listed('MoneyTest'))->toBe(['tests/Unit/MoneyTest.php', 'tests/Feature/MoneyTest.php'])
        ->and($listed('P\Tests\RateTest', 'MoneyTest'))->toBe(['tests/Unit/MoneyTest.php', 'tests/RateTest.php', 'tests/Feature/MoneyTest.php'])
        ->and($listed('P\Tests\Feature\MoneyTest'))->toBe(['tests/Feature/MoneyTest.php'])
        ->and($listed('Nowhere'))->toBe([]);
});

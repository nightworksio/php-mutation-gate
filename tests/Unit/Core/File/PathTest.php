<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;

it('spells a path with forward slashes', function (): void {
    expect(Path::of('src\\Core\\Money.php')->value())->toBe('src/Core/Money.php');
});

it('says whether it leads out of the directory it is spelt from', function (string $path, bool $escapes): void {
    expect(Path::of($path)->escapes())->toBe($escapes);
})->with([
    'inside' => ['src/Money.php', false],
    'the root' => ['.', false],
    'dots in a name' => ['src/..hidden/a..b', false],
    'up' => ['../x', true],
    'up from inside' => ['src/../../x', true],
    'a backslash spelling of up' => ['a\\..\\..\\x', true],
    'absolute' => ['/etc/passwd', true],
]);

it('names an entry inside a directory', function (): void {
    expect(Path::of('packages/money')->child(Path::of('composer.json')))->toEqual(Path::of('packages/money/composer.json'))
        ->and(Path::root()->child(Path::of('composer.json')))->toEqual(Path::of('composer.json'))
        ->and(Path::of('src')->child(Path::of('Http/Kernel.php')))->toEqual(Path::of('src/Http/Kernel.php'));
});

it('says whether it is the path of a PHP file', function (): void {
    expect(Path::of('src/Money.php')->isPhp())->toBeTrue()
        ->and(Path::of('src/Money.phpt')->isPhp())->toBeFalse()
        ->and(Path::of('composer.json')->isPhp())->toBeFalse();
});

it('names the file it ends in, without its .php', function (): void {
    expect(Path::of('tests/Unit/MoneyTest.php')->stem())->toBe('MoneyTest')
        ->and(Path::of('/abs/src/Money.php')->stem())->toBe('Money')
        ->and(Path::of('phpunit.xml')->stem())->toBe('phpunit.xml');
});

it('drops empty and current-directory segments', function (): void {
    expect(Path::of('./src//Core/./Money.php')->value())->toBe('src/Core/Money.php');
});

it('drops a trailing separator', function (): void {
    expect(Path::of('src/Core/')->value())->toBe('src/Core');
});

it('spells the root as a dot', function (string $root): void {
    expect(Path::of($root)->value())->toBe('.');
})->with(['', '.', './', '/./']);

it('names the root without spelling it', function (): void {
    expect(Path::root()->value())->toBe('.');
});

it('keeps an absolute path absolute', function (): void {
    expect(Path::of('/tmp//gate/')->value())->toBe('/tmp/gate');
});

it('equals a path spelt differently that names the same place', function (): void {
    expect(Path::of('src/')->equals(Path::of('./src')))->toBeTrue()
        ->and(Path::of('src')->equals(Path::of('tests')))->toBeFalse();
});

it('is within a directory it is inside or is, and not within one it only shares a prefix with', function (): void {
    expect(Path::of('src/Money.php')->within(Path::of('src')))->toBeTrue()
        ->and(Path::of('src')->within(Path::of('src')))->toBeTrue()
        ->and(Path::of('src/Money.php')->within(Path::root()))->toBeTrue()
        ->and(Path::of('srcs/Money.php')->within(Path::of('src')))->toBeFalse()
        ->and(Path::of('src')->within(Path::of('src/Money.php')))->toBeFalse();
});

it('is spelt from a directory it is inside', function (): void {
    expect(Path::of('packages/money/composer.json')->relativeTo(Path::of('packages/money'))->value())->toBe('composer.json')
        ->and(Path::of('packages/money/tests/Pest.php')->relativeTo(Path::of('packages'))->value())->toBe('money/tests/Pest.php');
});

it('is spelt as it is from a directory it is not inside, the root among them', function (): void {
    expect(Path::of('src/Money.php')->relativeTo(Path::of('packages/money'))->value())->toBe('src/Money.php')
        ->and(Path::of('src/Money.php')->relativeTo(Path::root())->value())->toBe('src/Money.php')
        ->and(Path::of('packages/moneyed/a.php')->relativeTo(Path::of('packages/money'))->value())->toBe('packages/moneyed/a.php');
});

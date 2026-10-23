<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Installation;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$at = static fn(string $root): Installation => Installation::at($root, Paths::of(Path::of('tests')), Path::of('lib/vendor'));

it('names its PHPUnit, Composer\'s list, its tests and its paths from its real root', function () use ($at): void {
    $root = (string) realpath(Scratch::directory());
    $installation = $at(sprintf('%s/.', $root));

    expect($installation->phpunit())->toBe(sprintf('%s/lib/vendor/bin/phpunit', $root))
        ->and($installation->installed())->toBe(sprintf('%s/lib/vendor/composer/installed.json', $root))
        ->and($installation->tests())->toEqual(Paths::of(Path::of('tests')))
        ->and($installation->absolute(Path::of('src/A.php')))->toBe(sprintf('%s/src/A.php', $root))
        ->and($installation->relative(sprintf('%s/src/A.php', $root)))->toEqual(Path::of('src/A.php'))
        ->and($at('/nowhere/at/all')->phpunit())->toBe('/nowhere/at/all/lib/vendor/bin/phpunit');
});

it('holds a PHPUnit to run only where its script is in the vendor directory, and is one in a package of its own', function () use ($at): void {
    $root = Scratch::directory();
    Scratch::write($root, 'packages/billing/lib/vendor/bin/phpunit', '<?php');
    $installation = $at($root);
    $billing = $installation->in(Path::of('packages/billing'), Paths::of(Path::of('spec')));

    expect($installation->hasPhpUnit())->toBeFalse()
        ->and($billing->hasPhpUnit())->toBeTrue()
        ->and($billing->tests())->toEqual(Paths::of(Path::of('spec')))
        ->and($billing->phpunit())->toBe(sprintf('%s/packages/billing/lib/vendor/bin/phpunit', (string) realpath($root)));
});

it('reads where the PHPUnit config PHPUnit reads has PHP print errors', function (string $name, string $text, ErrorDisplay|NotGiven $display) use ($at): void {
    $root = Scratch::directory();
    Scratch::write($root, $name, $text);

    expect($at($root)->errorDisplay())->toEqual($display)
        ->and($at($root)->config())->toBe(sprintf('%s/%s', (string) realpath($root), $name));
})->with([
    'phpunit.xml, hiding them' => ['phpunit.xml', '<phpunit><php><ini name="display_errors" value="0"/></php></phpunit>', ErrorDisplay::Nowhere],
    'phpunit.xml.dist, on standard error' => ['phpunit.xml.dist', '<phpunit><php><ini name="display_errors" value="stderr"/></php></phpunit>', ErrorDisplay::Stderr],
    'a config that sets none' => ['phpunit.dist.xml', '<phpunit/>', fn(): NotGiven => NotGiven::value()],
]);

it('reads the first of PHPUnit\'s names, and none where the project has no config', function () use ($at): void {
    $root = Scratch::directory();
    Scratch::write($root, 'phpunit.xml', '<phpunit><php><ini name="display_errors" value="0"/></php></phpunit>');
    Scratch::write($root, 'phpunit.dist.xml', '<phpunit><php><ini name="display_errors" value="1"/></php></phpunit>');

    expect($at($root)->errorDisplay())->toBe(ErrorDisplay::Nowhere)
        ->and($at(Scratch::directory())->errorDisplay())->toEqual(NotGiven::value())
        ->and($at(Scratch::directory())->config())->toEqual(NotGiven::value());
});

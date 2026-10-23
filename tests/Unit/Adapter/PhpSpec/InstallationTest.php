<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpSpec\Installation;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$at = static fn(string $root): Installation => Installation::at($root, Path::of('lib/vendor'));

it('names Composer\'s autoloader and list, and its paths, from its real root', function () use ($at): void {
    $root = (string) realpath(Scratch::directory());
    $installation = $at(sprintf('%s/.', $root));

    expect($installation->autoloader())->toBe(sprintf('%s/lib/vendor/autoload.php', $root))
        ->and($installation->installed())->toBe(sprintf('%s/lib/vendor/composer/installed.json', $root))
        ->and($installation->absolute(Path::of('spec/ASpec.php')))->toBe(sprintf('%s/spec/ASpec.php', $root))
        ->and($at('/nowhere/at/all')->autoloader())->toBe('/nowhere/at/all/lib/vendor/autoload.php');
});

it('holds a PhpSpec to run only where its script is in the vendor directory, and is one in a package of its own', function () use ($at): void {
    $root = Scratch::directory();
    Scratch::write($root, 'packages/billing/lib/vendor/bin/phpspec', '<?php');
    $installation = $at($root);
    $billing = $installation->in(Path::of('packages/billing'));

    expect($installation->hasPhpSpec())->toBeFalse()
        ->and($billing->hasPhpSpec())->toBeTrue()
        ->and($billing->autoloader())->toBe(sprintf('%s/packages/billing/lib/vendor/autoload.php', (string) realpath($root)));
});

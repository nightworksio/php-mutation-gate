<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Codeception\Installation;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$at = static fn(string $root): Installation => Installation::at($root, Path::of('lib/vendor'));

it('names Codeception\'s script, the vendor directory, Composer\'s list and its paths, from its real root', function () use ($at): void {
    $root = (string) realpath(Scratch::directory());
    $installation = $at(sprintf('%s/.', $root));

    expect($installation->codecept())->toBe(sprintf('%s/lib/vendor/bin/codecept', $root))
        ->and($installation->vendor())->toBe(sprintf('%s/lib/vendor', $root))
        ->and($installation->installed())->toBe(sprintf('%s/lib/vendor/composer/installed.json', $root))
        ->and($installation->absolute(Path::of('tests/ATest.php')))->toBe(sprintf('%s/tests/ATest.php', $root))
        ->and($at('/nowhere/at/all')->codecept())->toBe('/nowhere/at/all/lib/vendor/bin/codecept');
});

it('holds a Codeception to run only where its script is in the vendor directory, and is one in a package of its own', function () use ($at): void {
    $root = Scratch::directory();
    Scratch::write($root, 'packages/billing/lib/vendor/bin/codecept', '<?php');
    $installation = $at($root);
    $billing = $installation->in(Path::of('packages/billing'));

    expect($installation->hasCodeception())->toBeFalse()
        ->and($billing->hasCodeception())->toBeTrue()
        ->and($billing->codecept())->toBe(sprintf('%s/packages/billing/lib/vendor/bin/codecept', (string) realpath($root)));
});

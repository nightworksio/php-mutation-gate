<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Warm\BootSpec;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('is read back from the directory it was left in, whole', function (): void {
    $directory = Scratch::directory();
    $left = BootSpec::of('/project', '/project/vendor/autoload.php', 'vendor/pestphp/pest/bin/pest', ['Unit', 'Plugins']);
    $written = $left->leftIn($directory);
    $read = BootSpec::readIn($directory);

    expect($written)->toBeTrue()
        ->and($read)->toEqual($left)
        ->and($read instanceof BootSpec ? [$read->root(), $read->autoloader(), $read->script(), $read->suites()] : [])
        ->toBe(['/project', '/project/vendor/autoload.php', 'vendor/pestphp/pest/bin/pest', ['Unit', 'Plugins']]);
});

it('reads nothing where the directory holds none, or what it holds is not one', function (): void {
    $empty = Scratch::directory();
    $other = Scratch::directory();
    file_put_contents(sprintf('%s/boot.json', $other), '{"root": 3}');

    expect(BootSpec::readIn($empty))->toEqual(NotGiven::value())
        ->and(BootSpec::readIn($other))->toEqual(NotGiven::value());
});

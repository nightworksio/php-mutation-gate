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

it('is read back as booted by a worker of mutants\' runs, where it was left as one, and by a tail\'s worker otherwise', function (): void {
    $tail = Scratch::directory();
    $mutants = Scratch::directory();
    $spec = BootSpec::of('/project', '/project/vendor/autoload.php', 'vendor/pestphp/pest/bin/pest', ['Unit']);
    $spec->leftIn($tail);
    $spec->forMutants()->leftIn($mutants);
    file_put_contents(sprintf('%s/boot.json', $old = Scratch::directory()), '{"root":"/p","autoloader":"/p/a.php","script":"pest","suites":[]}');
    $read = BootSpec::readIn($mutants);

    expect(array_map(static fn(BootSpec|NotGiven $read): bool => $read instanceof BootSpec && $read->isForMutants(), [BootSpec::readIn($tail), $read, BootSpec::readIn($old)]))
        ->toBe([false, true, false])
        ->and($read instanceof BootSpec ? [$read->root(), $read->suites()] : [])->toBe(['/project', ['Unit']])
        ->and($spec->isForMutants())->toBeFalse();
});

it('reads nothing where the directory holds none, or what it holds is not one', function (): void {
    $empty = Scratch::directory();
    $other = Scratch::directory();
    file_put_contents(sprintf('%s/boot.json', $other), '{"root": 3}');

    expect(BootSpec::readIn($empty))->toEqual(NotGiven::value())
        ->and(BootSpec::readIn($other))->toEqual(NotGiven::value());
});

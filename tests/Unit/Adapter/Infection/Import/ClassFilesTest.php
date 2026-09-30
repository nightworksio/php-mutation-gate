<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Import\ClassFiles;
use NightWorksIO\MutationGate\Adapter\Infection\Import\Unmapped;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$classes = static fn(string $root): ClassFiles => ClassFiles::in(Project::at(Root::of($root), Paths::none(), Path::of('.gate')));

it('finds the first file the autoload would load a class from that is there', function () use ($classes): void {
    $root = Scratch::directory();
    Scratch::write($root, 'composer.json', '{"autoload": {"psr-4": {"Acme\\\\": ["src/", "lib/"]}}}');
    Scratch::write($root, 'lib/Money.php', '<?php');

    expect($classes($root)->of('Acme\\Money'))->toEqual(Path::of('lib/Money.php'))
        ->and($classes($root)->of('Acme\\Cents'))->toBe(Unmapped::NoFile);
});

it('finds no file for any class where composer.json is not there or not an object', function () use ($classes): void {
    $none = Scratch::directory();
    Scratch::write($none, 'src/Money.php', '<?php');
    $list = Scratch::directory();
    Scratch::write($list, 'composer.json', '[]');

    expect($classes($none)->of('Acme\\Money'))->toBe(Unmapped::NoFile)
        ->and($classes($list)->of('Acme\\Money'))->toBe(Unmapped::NoFile);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\OwnScript;
use NightWorksIO\MutationGate\Tests\Support\Tree;

it('is no archive where the gate runs from Composer\'s installation', function (): void {
    expect(OwnArchive::running())->toEqual(NotGiven::value());
});

it('is the archive whose root PHP names, and none where PHP names no archive\'s root', function (): void {
    $archive = OwnArchive::rootedAt('phar:///opt/mutation-gate.phar');

    expect($archive instanceof OwnArchive ? $archive->file() : $archive)->toBe('/opt/mutation-gate.phar')
        ->and(OwnArchive::rootedAt(''))->toEqual(NotGiven::value())
        ->and(OwnArchive::rootedAt('/opt/mutation-gate.phar'))->toEqual(NotGiven::value());
});

it('opens its scripts and its files inside the archive it runs from', function (): void {
    $archive = OwnArchive::at('/opt/mutation-gate.phar');

    expect($archive->file())->toBe('/opt/mutation-gate.phar')
        ->and($archive->root())->toBe('phar:///opt/mutation-gate.phar')
        ->and($archive->script(OwnScript::Worker))->toBe('phar:///opt/mutation-gate.phar/bin/mutation-gate-worker')
        ->and($archive->inside('composer.json'))->toBe('phar:///opt/mutation-gate.phar/composer.json');
});

it('is loaded in another process by its file and its name, whatever its file is called, and opens its autoloader by that name', function (): void {
    $archive = OwnArchive::at('/usr/local/bin/mutation-gate');

    expect($archive->loading())->toBe("\\Phar::loadPhar('/usr/local/bin/mutation-gate', 'mutation-gate.phar');\n")
        ->and($archive->autoloader())->toBe('phar://mutation-gate.phar/vendor/autoload.php');
});

it('runs the script a command line names first, and none where its first argument names none', function (): void {
    $archive = OwnArchive::at('/opt/mutation-gate.phar');

    expect($archive->scriptAskedBy('/opt/mutation-gate.phar', 'mutation-gate-worker', '/p/vendor/autoload.php'))->toBe(OwnScript::Worker)
        ->and($archive->scriptAskedBy('/opt/mutation-gate.phar', 'run', 'mutation-gate-worker'))->toEqual(NotGiven::value())
        ->and($archive->scriptAskedBy('/opt/mutation-gate.phar', 'mutation-gate-phpspec'))->toBe(OwnScript::PhpSpec)
        ->and($archive->scriptAskedBy('/opt/mutation-gate.phar'))->toEqual(NotGiven::value())
        ->and($archive->scriptAskedBy())->toEqual(NotGiven::value());
});

it('is known by the name its stub maps it to', function (): void {
    expect((string) file_get_contents(Tree::at('resources/phar-stub.php')))->toContain(sprintf("Phar::mapPhar('%s');", OwnArchive::ALIAS))
        ->and(OwnArchive::ALIAS)->toBe('mutation-gate.phar');
});

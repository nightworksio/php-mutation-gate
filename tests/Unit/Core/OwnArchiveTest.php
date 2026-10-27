<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\OwnScript;

it('is no archive where the gate runs from Composer\'s installation', function (): void {
    expect(OwnArchive::running())->toEqual(NotGiven::value());
});

it('opens its autoloader, its scripts and its files inside the archive', function (): void {
    $archive = OwnArchive::at('/opt/mutation-gate.phar');

    expect($archive->file())->toBe('/opt/mutation-gate.phar')
        ->and($archive->autoloader())->toBe('phar:///opt/mutation-gate.phar/vendor/autoload.php')
        ->and($archive->script(OwnScript::Worker))->toBe('phar:///opt/mutation-gate.phar/bin/mutation-gate-worker')
        ->and($archive->inside('composer.json'))->toBe('phar:///opt/mutation-gate.phar/composer.json');
});

it('runs the script a command line names first, and none where its first argument names none', function (): void {
    $archive = OwnArchive::at('/opt/mutation-gate.phar');

    expect($archive->scriptAskedBy('/opt/mutation-gate.phar', 'mutation-gate-worker', '/p/vendor/autoload.php'))->toBe(OwnScript::Worker)
        ->and($archive->scriptAskedBy('/opt/mutation-gate.phar', 'run', 'mutation-gate-worker'))->toEqual(NotGiven::value())
        ->and($archive->scriptAskedBy('/opt/mutation-gate.phar'))->toEqual(NotGiven::value());
});

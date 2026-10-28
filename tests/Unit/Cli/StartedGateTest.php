<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\StartedGate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    putenv('COMPOSER_BIN_DIR');
    Scratch::sweep();
});

it('started from Composer\'s installation, is the binary Composer links, where the manifest says', function (): void {
    $bare = Scratch::directory();
    $moved = Scratch::directory();
    Scratch::write($moved, 'composer.json', '{"config": {"bin-dir": "tools"}}');

    expect(StartedGate::from($bare, NotGiven::value()))->toEqual(GateCommand::installed())
        ->and(StartedGate::from($moved, NotGiven::value())->gate())->toBe('tools/mutation-gate')
        ->and(StartedGate::in($bare))->toEqual(GateCommand::installed());
});

it('started from a PHAR inside the project, is that file as the project\'s root spells it, run by PHP where it cannot run itself', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'tools/mutation-gate', '');
    Scratch::write($project, 'mutation-gate.phar', '');
    chmod(sprintf('%s/mutation-gate.phar', $project), 0755);

    expect(StartedGate::from($project, OwnArchive::at(sprintf('%s/tools/mutation-gate', $project))))
        ->toEqual(GateCommand::byPhp(Path::of('tools/mutation-gate')))
        ->and(StartedGate::from($project, OwnArchive::at(sprintf('%s/mutation-gate.phar', $project)))->gate())
        ->toBe('./mutation-gate.phar');
});

it('started from a PHAR outside the project, is that file by the path it was started from', function (): void {
    $project = Scratch::directory();
    $elsewhere = Scratch::directory();
    Scratch::write($elsewhere, 'mutation-gate', '');
    $archive = sprintf('%s/mutation-gate', realpath($elsewhere));

    expect(StartedGate::from($project, OwnArchive::at($archive)))->toEqual(GateCommand::byPhp(Path::of($archive)));
});

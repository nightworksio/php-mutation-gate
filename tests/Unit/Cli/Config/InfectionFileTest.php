<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\InfectionFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('takes the file the command line names, or the first Infection itself would read', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'infection.json.dist', '{}');
    Scratch::write($project, 'infection.json5.dist', '{}');

    expect(InfectionFile::named('config/infection.json5')->in($project))->toEqual(Path::of('config/infection.json5'))
        ->and(InfectionFile::named('')->in($project))->toEqual(Path::of('infection.json5.dist'));
});

it('cannot judge a project with no Infection config to import from', function (): void {
    expect(InfectionFile::named('')->in(Scratch::directory()))
        ->toEqual(CannotJudge::because('There is no infection.json5, infection.json or .dist of either here to import from.'));
});

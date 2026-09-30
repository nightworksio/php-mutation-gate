<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Json\JsonConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('cannot judge a file that is not JSON, naming it', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"runner": ');
    $file = sprintf('%s/mutation-gate.json', $project);

    expect(new JsonConfig()->load(ConfigFile::at(Path::of($file), Path::of($project))))
        ->toEqual(CannotJudge::because(sprintf('%s is not JSON: Syntax error.', $file)));
});

it('cannot judge a directory where the file should be', function (): void {
    $project = Scratch::directory();

    expect(new JsonConfig()->load(ConfigFile::at(Path::of($project), Path::of($project))))
        ->toEqual(CannotJudge::because(sprintf('%s could not be read.', $project)));
});

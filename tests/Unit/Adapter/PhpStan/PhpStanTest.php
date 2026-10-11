<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\PhpStan;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\FakeAnalyser;
use NightWorksIO\MutationGate\Tests\Support\PhpStanProjects;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
    putenv('MUTATION_GATE_CONTRACT_TOKEN');
});

it('refuses a config option that is no path', function (): void {
    expect(PhpStan::fromOptions(Configs::options('{"config": 5}'), Scratch::directory(), new LocalProcesses(new SystemClock())))
        ->toEqual(Invalid::because(Problem::at('config', 'expected a path, got 5')));
});

it('cannot judge where it cannot write its own config', function (): void {
    $project = FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16');
    Scratch::write($project, '.mutation-gate/phpstan', 'a file where the directory belongs');

    expect(PhpStanProjects::in($project)->findings(Paths::none(), Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf('The gate cannot write PHPStan\'s config for its checks to %s/.mutation-gate/phpstan/check.neon.', $project)));
});

it('cannot judge in a root that is not there, where its process never starts', function (): void {
    $gone = PhpStan::fromOptions(Configs::options('{}'), sprintf('%s/gone', Scratch::directory()), new LocalProcesses(new SystemClock()));

    expect($gone instanceof PhpStan ? $gone->identity(Withheld::standard()) : $gone)
        ->toEqual(CannotJudge::because('PHPStan has no config to read: add a phpstan.neon, or name one in staticCheck.config.'));
});

it('reads no dependents a check lists, finding them itself', function (): void {
    expect(PhpStanProjects::in(FakeAnalyser::phpstan('PHPStan - PHP Static Analysis Tool 2.2.16'))->readsDependents())->toBeFalse();
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Mago\Mago;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\FakeAnalyser;
use NightWorksIO\MutationGate\Tests\Support\MagoProjects;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('refuses a config option that is no path', function (): void {
    expect(Mago::fromOptions(Configs::options('{"config": 5}'), Scratch::directory(), 'vendor', new LocalProcesses(new SystemClock())))
        ->toEqual(Invalid::because(Problem::at('config', 'expected a path, got 5')));
});

it('never downloads its binary: it cannot judge until Composer\'s package has', function (): void {
    $project = Scratch::directory();
    Scratch::write($project, 'vendor/composer/installed.json', '{"packages": [{"name": "carthage-software/mago", "version": "1.50.0"}]}');

    expect(MagoProjects::in($project)->identity(Withheld::standard()))
        ->toEqual(CannotJudge::because('Mago 1.50.0 has not downloaded its binary yet: run vendor/bin/mago --version once, which downloads it.'))
        ->and(MagoProjects::in($project)->check(MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'))))
        ->toBeInstanceOf(CannotJudge::class);
});

it('cannot judge where its process never starts', function (): void {
    $project = FakeAnalyser::mago('1.50.0', '');
    $gone = MagoProjects::in(sprintf('%s/gone', $project), '{}', sprintf('%s/vendor', $project));

    expect($gone->identity(Withheld::standard()))->toEqual(CannotJudge::because(sprintf(
        'Mago did not say its version (it did not run: The provided cwd "%s/gone" does not exist.).',
        $project,
    )));
});

it('reads no dependents a check lists, analysing the whole workspace', function (): void {
    expect(MagoProjects::in(FakeAnalyser::mago('1.50.0', "src/Money.php\0"))->readsDependents())->toBeFalse();
});

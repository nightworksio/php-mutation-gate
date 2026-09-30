<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Doctored;
use NightWorksIO\MutationGate\Tests\Support\FakePhp;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

$given = static fn(string $runner = ''): CommandLine => new CommandLine('', $runner, [], '', '', firstPartyOnly: false);

it('observes the config, the runner, the trees, .gitignore, the Infection config and the runner\'s PHP', function () use ($given): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Library');
    Scratch::write($project, '.gitignore', ".mutation-gate/\n");
    Scratch::write($project, 'infection.json5', '{minMsi: 80, initialTestsPhpOptions: "-d memory_limit=1G"}');
    $php = FakePhp::answering("[PHP Modules]\npcov\n", "extension_dir => /nowhere => /nowhere\n");
    $observed = Doctored::observed($project, sprintf('%s/php', $php))->of($given());
    $trees = $observed->trees();

    expect($observed->settings())->toBeInstanceOf(Settings::class)
        ->and($observed->runners())->toEqual(InstalledRunners::of(pest: false, infection: true, chosen: false))
        ->and($trees instanceof Trees ? array_map(static fn(Tree $tree): string => $tree->path()->value(), [...$trees]) : [])->toBe(['src'])
        ->and($observed->gitIgnore() instanceof GitIgnore && $observed->gitIgnore()->names(Path::of('.mutation-gate')))->toBeTrue()
        ->and($observed->infection())->toEqual(InfectionConfig::in('infection.json5', minMsi: true, ignores: false))
        ->and($observed->php() instanceof RunnerPhp && $observed->php()->loads('pcov'))->toBeTrue()
        ->and(trim((string) file_get_contents(sprintf('%s/arguments.txt', $php))))->toBe('-d memory_limit=1G -i');
});

it('observes both runners left to choose, and no trees where the config cannot be used', function () use ($given): void {
    $project = Scratch::copy('tests/Fixtures/Projects/TwoRunners');
    $php = FakePhp::answering("[PHP Modules]\npcov\n", '');
    $observed = Doctored::observed($project, sprintf('%s/php', $php))->of($given());

    expect($observed->runners())->toEqual(InstalledRunners::of(pest: true, infection: true, chosen: false))
        ->and($observed->settings())->toBeInstanceOf(CannotJudge::class)
        ->and($observed->trees())->toEqual(NotGiven::value())
        ->and($observed->infection())->toEqual(NotGiven::value())
        ->and($observed->gitIgnore() instanceof GitIgnore && $observed->gitIgnore()->names(Path::of('.mutation-gate')))->toBeFalse()
        ->and(trim((string) file_get_contents(sprintf('%s/arguments.txt', $php))))->toBe('-i');
});

it('observes a runner the command line chooses, and none installed where Composer lists nothing', function () use ($given): void {
    $two = Scratch::copy('tests/Fixtures/Projects/TwoRunners');
    $none = Scratch::directory();
    Scratch::write($none, 'composer.json', '{}');
    $php = sprintf('%s/php', FakePhp::answering('', ''));

    expect(Doctored::observed($two, $php)->of($given('pest'))->runners())
        ->toEqual(InstalledRunners::of(pest: true, infection: true, chosen: true))
        ->and(Doctored::observed($none, $php)->of($given())->runners())
        ->toEqual(InstalledRunners::of(pest: false, infection: false, chosen: false));
});

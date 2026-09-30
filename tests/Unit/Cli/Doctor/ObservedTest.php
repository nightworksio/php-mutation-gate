<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\ComposerSetup;
use NightWorksIO\MutationGate\Core\Doctor\InfectionConfig;
use NightWorksIO\MutationGate\Core\Doctor\InstalledRunners;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedger;
use NightWorksIO\MutationGate\Core\Doctor\KeptLedgers;
use NightWorksIO\MutationGate\Core\Doctor\RunnerPhp;
use NightWorksIO\MutationGate\Core\File\GitIgnore;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\Doctored;
use NightWorksIO\MutationGate\Tests\Support\FakePhp;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

$here = (string) getcwd();

afterEach(function () use ($here): void {
    chdir($here);
    Scratch::sweep();
});

it('observes the config, the runner, the trees, .gitignore, the Infection config and the runner\'s PHP', function (): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Library');
    Scratch::write($project, '.gitignore', ".mutation-gate/\n");
    Scratch::write($project, 'infection.json5', '{minMsi: 80, initialTestsPhpOptions: "-d memory_limit=1G"}');
    $php = FakePhp::printing(Described::output(['pcov' => '1.0.12'], ['extension_dir' => '/nowhere']));
    $observed = Doctored::observed($project, sprintf('%s/php', $php))->of(CommandLine::nothing());
    $trees = $observed->trees();

    expect($observed->settings())->toBeInstanceOf(Settings::class)
        ->and($observed->runners())->toEqual(InstalledRunners::of(pest: false, infection: true, chosen: false))
        ->and($trees instanceof Trees ? array_map(static fn(Tree $tree): string => $tree->path()->value(), [...$trees]) : [])->toBe(['src'])
        ->and($observed->files()->gitIgnore() instanceof GitIgnore && $observed->files()->gitIgnore()->names(Path::of('.mutation-gate')))->toBeTrue()
        ->and($observed->files()->infection())->toEqual(InfectionConfig::in('infection.json5', minMsi: true, ignores: false))
        ->and($observed->php() instanceof RunnerPhp && $observed->php()->loads('pcov'))->toBeTrue()
        ->and(trim((string) file_get_contents(sprintf('%s/arguments.txt', $php))))->toBe(sprintf('-d memory_limit=1G -r %s', Platform::describing()[1]));
});

it('observes the markers the chosen runner finds in the trees, and the path repositories that copy their packages', function (): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Library');
    Scratch::write($project, 'src/Held.php', "<?php\n// @infection-ignore-all\n");
    Scratch::write($project, 'packages/money/README.md', 'Money');
    Scratch::write($project, 'composer.json', <<<'JSON'
        {
            "name": "acme/library",
            "autoload": {"psr-4": {"Acme\\": "src/"}},
            "repositories": [
                {"type": "path", "url": "packages/*", "options": {"symlink": false}},
                {"type": "path", "url": "libs/clock"}
            ]
        }
        JSON);
    $php = FakePhp::printing(Described::output(['pcov' => '1.0.12'], []));
    $observed = Doctored::observed($project, sprintf('%s/php', $php))->of(CommandLine::nothing());
    $markers = $observed->markers();

    expect($markers instanceof Markers ? array_map(static fn(Marker $marker): string => $marker->where(), [...$markers]) : [])
        ->toBe(['src/Held.php:2'])
        ->and($observed->files()->composer())->toEqual(ComposerSetup::of(Paths::of(Path::of('packages/money'))))
        ->and($observed->now())->toEqual(new DateTimeImmutable(Configs::NOW));
});

it('observes the CI definitions that run the gate, the baseline, and the ledgers the directory store keeps', function (): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Library');
    Scratch::write($project, '.github/workflows/mutation.yml', "steps:\n  - run: vendor/bin/mutation-gate run\n");
    Scratch::write($project, '.github/workflows/tests.yml', "steps:\n  - run: vendor/bin/pest\n");
    Scratch::write($project, '.gitlab-ci.yml', "mutation:\n  script: vendor/bin/mutation-gate run\n");
    Scratch::write($project, 'mutation-gate.baseline.json', BaselineFile::encode(Baseline::of(Entry::of(Path::of('src'), Floor::of(70)))));
    Scratch::write($project, '.mutation-gate/ledger/refs/heads/main/ledger.json.gz', LedgerFile::encode(Ledger::empty()));
    $php = FakePhp::printing(Described::output(['pcov' => '1.0.12'], []));
    $observed = Doctored::observed($project, sprintf('%s/php', $php))->of(CommandLine::nothing());
    $ledgers = $observed->ledgers();

    expect($observed->files()->runningTheGate())->toEqual(Paths::of(Path::of('.github/workflows/mutation.yml'), Path::of('.gitlab-ci.yml')))
        ->and($observed->files()->baseline())->toEqual(Baseline::of(Entry::of(Path::of('src'), Floor::of(70))))
        ->and($ledgers instanceof KeptLedgers ? array_map(static fn(KeptLedger $ledger): string => $ledger->file()->value(), [...$ledgers]) : [])
        ->toBe(['.mutation-gate/ledger/refs/heads/main/ledger.json.gz']);
});

it('observes an empty baseline where there is none, and why one cannot be read', function (): void {
    $none = Scratch::copy('tests/Fixtures/Projects/Library');
    $broken = Scratch::copy('tests/Fixtures/Projects/Library');
    Scratch::write($broken, 'mutation-gate.baseline.json', '{');
    $php = sprintf('%s/php', FakePhp::printing(Described::output([], [])));

    expect(Doctored::observed($none, $php)->of(CommandLine::nothing())->files()->baseline())->toEqual(Baseline::none())
        ->and(Doctored::observed($broken, $php)->of(CommandLine::nothing())->files()->baseline())->toBeInstanceOf(CannotJudge::class);
});

it('observes no markers where the chosen runner cannot be built, and nothing of a project with no composer.json', function (): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Library');
    Scratch::write($project, 'mutation-gate.json', '{"runner": "\\\\Acme\\\\Missing"}');
    $bare = Scratch::directory();
    $php = sprintf('%s/php', FakePhp::printing(Described::output([], [])));
    $bareObserved = Doctored::observed($bare, $php)->of(CommandLine::nothing()->withRunner('infection'));

    $observed = Doctored::observed($project, $php)->of(CommandLine::nothing());

    expect($observed->settings())->toBeInstanceOf(Settings::class)
        ->and($observed->trees())->toBeInstanceOf(Trees::class)
        ->and($observed->markers())->toEqual(NotGiven::value())
        ->and($bareObserved->files()->composer())->toEqual(NotGiven::value());
});

it('observes both runners left to choose, and no trees where the config cannot be used', function (): void {
    $project = Scratch::copy('tests/Fixtures/Projects/TwoRunners');
    $php = FakePhp::printing(Described::output(['pcov' => '1.0.12'], []));
    $observed = Doctored::observed($project, sprintf('%s/php', $php))->of(CommandLine::nothing());

    expect($observed->runners())->toEqual(InstalledRunners::of(pest: true, infection: true, chosen: false))
        ->and($observed->settings())->toBeInstanceOf(CannotJudge::class)
        ->and($observed->trees())->toEqual(NotGiven::value())
        ->and($observed->files()->infection())->toEqual(NotGiven::value())
        ->and($observed->files()->gitIgnore() instanceof GitIgnore && $observed->files()->gitIgnore()->names(Path::of('.mutation-gate')))->toBeFalse()
        ->and(trim((string) file_get_contents(sprintf('%s/arguments.txt', $php))))->toBe(sprintf('-r %s', Platform::describing()[1]));
});

it('observes a runner the command line chooses, and none installed where Composer lists nothing', function (): void {
    $two = Scratch::copy('tests/Fixtures/Projects/TwoRunners');
    $none = Scratch::directory();
    Scratch::write($none, 'composer.json', '{}');
    $php = sprintf('%s/php', FakePhp::printing(Described::output([], [])));

    expect(Doctored::observed($two, $php)->of(CommandLine::nothing()->withRunner('pest'))->runners())
        ->toEqual(InstalledRunners::of(pest: true, infection: true, chosen: true))
        ->and(Doctored::observed($none, $php)->of(CommandLine::nothing())->runners())
        ->toEqual(InstalledRunners::of(pest: false, infection: false, chosen: false));
});

it('hands the runner\'s PHP none of the CI plan\'s credentials, nor those the runner\'s config withholds', function (): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Library');
    Scratch::write($project, 'mutation-gate.json', '{"runner": {"use": "infection", "withhold": ["DEPLOY_*"]}}');
    $php = FakePhp::printing(Described::output(['pcov' => '1.0.12'], []));
    $environment = ['CI_JOB_TOKEN' => 'job', 'DEPLOY_KEY' => 'key', 'GITHUB_TOKEN' => 'token', 'KEPT' => 'yes'];
    Doctored::observed($project, sprintf('%s/php', $php), $environment)
        ->of(CommandLine::nothing()->withCi('gitlab'));
    $seen = (string) file_get_contents(sprintf('%s/environment.txt', $php));

    expect(array_map(static fn(string $name): bool => str_contains($seen, sprintf('%s=', $name)), array_keys($environment)))
        ->toBe([false, false, false, true]);
});

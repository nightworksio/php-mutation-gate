<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Registered;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Canonical;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\ChosenRunner;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\ConfigReads;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\IgnoredMutant;
use NightWorksIO\MutationGate\Core\Config\Ignores;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\PresetSet;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ConfigLoader;
use NightWorksIO\MutationGate\Tests\Fakes\ExtensionFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    Scratch::sweep();
});

/** The effective config of a project, with this package's own extension, and YAML and NEON installed or not. */
$effective = static fn(string $project, bool $installed = true): Effective => new Effective(
    $project,
    Registered::config(new Extensions(Origin::of('nightworksio/mutation-gate')), static fn(): bool => $installed),
    new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project))),
    new DateTimeImmutable(Configs::NOW),
);

/** Nothing said on the command line. */
$nothing = static fn(): CommandLine => CommandLine::nothing();

/** @return array<mixed> */
$shown = static fn(Settings|Invalid|CannotJudge $settings): array => $settings instanceof Settings
    ? (static fn(mixed $shown): array => is_array($shown) ? $shown : [])(Configs::decoded($settings->effective()))
    : ['not settings' => Configs::problems($settings)];

it('keeps a preset\'s mutator sets offered, so a run skips one nothing registers', function () use ($effective, $nothing): void {
    $settings = $effective(Tree::at('tests/Fixtures/Projects/Laravel'))->settings($nothing());
    $offered = $settings instanceof Settings ? $settings->mutators()->offering(Name::of('laravel')) : $settings;

    expect($offered)->toBeInstanceOf(PresetSet::class)
        ->and($offered instanceof PresetSet ? [$offered->preset()->value(), $offered->package()] : $offered)
        ->toBe(['laravel', 'nightworksio/mutation-gate-laravel']);
});

it('finds the preset and the runner of a project with no config', function () use (
    $effective,
    $nothing,
    $shown,
): void {
    $settings = $effective(Tree::at('tests/Fixtures/Projects/Laravel'))->settings($nothing());

    expect($shown($settings))->toMatchArray([
        'preset' => 'laravel',
        'runner' => ['use' => 'pest', 'memory' => '1G'],
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]],
        'reach' => ['everything' => ['bootstrap/**', 'config/**', 'routes/**', '.env.testing']],
        'timeouts' => ['mode' => 'confirm', 'seconds' => 30, 'most' => 300, 'tighter' => ['mutators' => TighterSilence::MUTATORS, 'floor' => TighterSilence::FLOOR]],
    ]);
});

it('lays the config file over its presets, and the command line over both', function () use (
    $effective,
    $shown,
): void {
    $settings = $effective(Tree::at('tests/Fixtures/Projects/Configured'))
        ->settings(CommandLine::nothing()->withRunner('pest')->withReport('json:build/mutation.json')->withBudget('5m')->withCi('github'));

    expect($shown($settings))->toMatchArray([
        'preset' => 'symfony',
        'runner' => ['use' => 'pest', 'memory' => '1G'],
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['src']]],
        'trees' => [['path' => 'src/Domain', 'floor' => 100]],
        'reach' => ['everything' => ['config/**', '.env.test', 'tests/bootstrap.php', 'migrations/**']],
        'budget' => '5m',
        'timeouts' => ['mode' => 'confirm', 'seconds' => 30, 'most' => 300, 'tighter' => ['mutators' => TighterSilence::MUTATORS, 'floor' => TighterSilence::FLOOR]],
        'reports' => [
            ['use' => 'sarif', 'path' => 'build/mutation.sarif'],
            ['use' => 'json', 'path' => 'build/mutation.json'],
        ],
    ])->and($settings instanceof Settings ? $settings->ci()->plan() : $settings)
        ->toEqual(Choice::of('github', Configs::commandLine('{}')));
});

it('takes the runner from the config file before looking for one', function () use ($effective, $nothing): void {
    $settings = $effective(Tree::at('tests/Fixtures/Projects/Configured'))->settings($nothing());

    expect($settings instanceof Settings ? $settings->runner()->choice() : $settings)
        ->toEqual(Choice::of('infection', Configs::options('{}')));
});

it('finds the runner of a config that only says what it withholds', function () use ($effective, $nothing): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    Scratch::write($project, 'mutation-gate.json', '{"runner": {"withhold": ["DEPLOY_*"]}}');
    $settings = $effective($project)->settings($nothing());
    $runner = $settings instanceof Settings ? $settings->runner() : $settings;

    expect($runner instanceof ChosenRunner ? [$runner->choice(), $runner->withhold()] : $runner)
        ->toEqual([
            Choice::of('pest', Configs::options('{"patch": false, "canary": "mutation-canary"}')),
            Withheld::of('DEPLOY_*'),
        ]);
});

it('keeps what the config withholds where the command line chooses the runner', function () use ($effective): void {
    $project = Scratch::copy('tests/Fixtures/Projects/Laravel');
    Scratch::write($project, 'mutation-gate.json', '{"runner": {"use": "pest", "withhold": ["DEPLOY_*"]}}');
    $settings = $effective($project)->settings(CommandLine::nothing()->withRunner('infection'));
    $runner = $settings instanceof Settings ? $settings->runner() : $settings;

    expect($runner instanceof ChosenRunner ? [$runner->choice(), $runner->withhold()] : $runner)
        ->toEqual([Choice::of('infection', Configs::commandLine('{}')), Withheld::of('DEPLOY_*')]);
});

it('applies a list of presets in order, the later winning', function () use (
    $effective,
    $nothing,
    $shown,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"preset": ["symfony", "laravel"], "runner": "pest"}');

    expect($shown($effective($project)->settings($nothing())))->toMatchArray([
        'preset' => ['symfony', 'laravel'],
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]],
        'reach' => ['everything' => [
            'config/**',
            '.env.test',
            'tests/bootstrap.php',
            'bootstrap/**',
            'routes/**',
            '.env.testing',
        ]],
    ]);
});

it('gives a library listed after a framework its own timeout and floor', function () use (
    $effective,
    $nothing,
    $shown,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"preset": ["laravel", "library"], "runner": "pest"}');
    Scratch::write($project, 'framework.json', '{"preset": ["library", "laravel"], "runner": "pest"}');
    $framework = CommandLine::nothing()->withConfig('framework.json');

    expect($shown($effective($project)->settings($nothing())))->toMatchArray([
        'newCode' => ['floor' => 100],
        'timeouts' => ['seconds' => 10, 'most' => 300, 'mode' => 'confirm', 'tighter' => ['mutators' => TighterSilence::MUTATORS, 'floor' => TighterSilence::FLOOR]],
    ])->and($shown($effective($project)->settings($framework)))->toMatchArray([
        'newCode' => ['floor' => 100],
        'timeouts' => ['seconds' => 30, 'most' => 300, 'mode' => 'confirm', 'tighter' => ['mutators' => TighterSilence::MUTATORS, 'floor' => TighterSilence::FLOOR]],
    ]);
});

it('chooses the library preset for a project without composer.json', function () use (
    $effective,
    $nothing,
    $shown,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.yaml', "runner: infection\n");

    expect($shown($effective($project)->settings($nothing())))->toMatchArray([
        'preset' => 'library',
        'runner' => ['use' => 'infection', 'memory' => '1G'],
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => []]],
        'timeouts' => ['mode' => 'confirm', 'seconds' => 10, 'most' => 300, 'tighter' => ['mutators' => TighterSilence::MUTATORS, 'floor' => TighterSilence::FLOOR]],
    ]);
});

it('reports every problem of an invalid config', function () use ($effective, $nothing): void {
    expect(Configs::problems($effective(Tree::at('tests/Fixtures/Projects/Invalid'))->settings($nothing())))->toBe([
        'trees[0].floor: expected a number from 0 to 100, got "80"',
        'newcode: unknown key, did you mean newCode?',
    ]);
});

it('cannot judge what the project leaves undecided', function (string $project, CommandLine $given, string $why) use (
    $effective,
): void {
    $root = Tree::at(sprintf('tests/Fixtures/Projects/%s', $project));

    expect($effective($root, installed: false)->settings($given))->toEqual(CannotJudge::because(sprintf($why, $root)));
})->with([
    'two config files' => [
        'TwoConfigs',
        CommandLine::nothing(),
        'More than one config file is here: mutation-gate.json, mutation-gate.yaml. '
        . 'Keep one, or name one with --config.',
    ],
    'two runners' => [
        'TwoRunners',
        CommandLine::nothing(),
        'Both pestphp/pest-plugin-mutate and infection/infection are installed. '
        . 'Choose one: set runner in the config, or pass --runner.',
    ],
    'a YAML config without its library' => [
        'TwoConfigs',
        CommandLine::nothing()->withConfig('mutation-gate.yaml'),
        '%s/mutation-gate.yaml is YAML, which needs symfony/yaml to be read. '
        . 'Install it: composer require --dev symfony/yaml',
    ],
]);

it('lets the command line choose the runner where two are installed', function () use ($effective): void {
    $settings = $effective(Tree::at('tests/Fixtures/Projects/TwoRunners'))
        ->settings(CommandLine::nothing()->withRunner('infection'));

    expect($settings instanceof Settings ? $settings->runner()->choice() : $settings)
        ->toEqual(Choice::of('infection', Configs::commandLine('{}')));
});

it('reports a preset nothing registered at its path, with what only every layer together can say', function (
    string $preset,
    array $problems,
) use ($effective, $nothing): void {
    $project = Scratch::directory();
    $ignores = '{"maxDays": 1, "entries": [{"mutant": "81d0c9e2aa17", "reason": "Equivalent"}]}';
    $config = sprintf('{"preset": %s, "runner": "pest", "ignores": %s}', $preset, $ignores);
    Scratch::write($project, 'mutation-gate.json', $config);

    expect(Configs::problems($effective($project)->settings($nothing())))->toBe($problems);
})->with([
    'one preset, misspelt' => ['"larvel"', [
        'preset: No preset is registered as "larvel". Did you mean "laravel"?',
        'ignores.entries[0].expires: expected a date by 2026-10-01, within ignores.maxDays of today, got nothing',
    ]],
    'the second of a list, far from any' => ['["symfony", "acme"]', [
        'preset[1]: No preset is registered as "acme".',
        'ignores.entries[0].expires: expected a date by 2026-10-01, within ignores.maxDays of today, got nothing',
    ]],
]);

it('reports what is wrong in a layer before what the presets or every layer together can say', function () use (
    $effective,
    $nothing,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"preset": "acme", "budget": "soon"}');

    expect(Configs::problems($effective($project)->settings($nothing())))
        ->toBe(['budget: expected a duration such as 90s, 15m, 1h30m or 7d, got "soon"'])
        ->and(Configs::problems($effective($project)->settings(CommandLine::nothing()->withRunner('pest')->withBudget('soon'))))
        ->toBe(['budget: expected a duration such as 90s, 15m, 1h30m or 7d, got "soon"']);
});

it('reports a preset nothing registered where the rest of the config is valid', function () use (
    $effective,
    $nothing,
): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"preset": ["library", "acme"], "runner": "pest"}');

    expect($effective($project)->settings($nothing()))
        ->toEqual(Invalid::because(Problem::at('preset[1]', 'No preset is registered as "acme".')));
});

it('reports a preset nothing registered where it cannot judge the rest', function () use ($effective, $nothing): void {
    $project = Scratch::copy('tests/Fixtures/Projects/TwoRunners');
    Scratch::write($project, 'mutation-gate.json', '{"preset": "acme"}');

    expect(Configs::problems($effective($project)->settings($nothing())))->toBe([
        'preset: No preset is registered as "acme".',
        ': Both pestphp/pest-plugin-mutate and infection/infection are installed. '
        . 'Choose one: set runner in the config, or pass --runner.',
    ]);
});

it('cannot judge a config file it cannot read', function () use ($effective, $nothing): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"runner": ');

    expect($effective($project)->settings($nothing()))->toEqual(CannotJudge::because(sprintf(
        '%s/mutation-gate.json is not JSON: Syntax error.',
        $project,
    )));
});

it('loads the extensions the config file names, unless told to load none', function () use ($effective): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', (string) json_encode([
        'extensions' => [ExtensionFake::class, 'Acme\\Missing\\Extension'],
        'runner' => 'pest',
    ]));

    expect($effective($project)->settings(CommandLine::nothing()->firstPartyOnly()))
        ->toBeInstanceOf(Settings::class)
        ->and($effective($project)->settings(CommandLine::nothing()))
        ->toEqual(CannotJudge::because(
            'the config file names Acme\\Missing\\Extension in extensions, and it is not a class that implements '
            . 'NightWorksIO\\MutationGate\\Extension\\Extension.',
        ));
});

it('reads the config file --config names', function () use ($effective, $shown): void {
    $project = Scratch::directory();
    Scratch::write($project, 'ci/gate.neon', "runner: pest\nbudget: 90s\n");

    expect($shown($effective($project)->settings(CommandLine::nothing()->withConfig('ci/gate.neon'))))
        ->toMatchArray(['runner' => ['use' => 'pest', 'memory' => '1G'], 'budget' => '1m30s']);
});

it('cannot judge a project whose composer.json it cannot read, where it must choose the preset', function () use (
    $effective,
    $nothing,
): void {
    $project = Scratch::directory();
    mkdir(sprintf('%s/composer.json', $project));
    Scratch::write($project, 'mutation-gate.json', '{"runner": "pest"}');

    expect($effective($project)->settings($nothing()))->toBeInstanceOf(CannotJudge::class)
        ->and(Configs::problems($effective($project)->settings($nothing()))[0])
        ->toEndWith('/composer.json could not be read.');
});

it('judges a layer a loader or a preset built by hand, as the definition judges a file', function (
    string $config,
    string $at,
): void {
    $unexplained = IgnoredMutant::of(MutantId::hash(Path::of('src/A.php'), 'Plus', '-a+b', 0), '', Absent::setting());
    $smuggled = Layer::of(
        Setup::of(runner: Choice::of('pest', Configs::options('{}'))),
        Floors::of(trees: Listed::of(DeclaredTree::of(Path::of('src'), Floor::of(0), Listed::of()))),
        Triage::of(limit: Seconds::of(-5)),
        Shards::of(seconds: Seconds::of(600), max: 0, target: Seconds::of(1200)),
        Ignores::of(entries: Listed::of($unexplained)),
    );
    $loader = new readonly class ($smuggled) implements ConfigLoader {
        public function __construct(private Layer $layer)
        {
        }

        public function load(ConfigFile $file): Layer
        {
            return $this->layer;
        }

        public function reads(ConfigFile $file): ConfigReads
        {
            return ConfigReads::none();
        }
    };
    $extensions = new Extensions(Origin::of('acme/smuggler'))
        ->withConfigLoader(Name::of('smuggled'), static fn(): ConfigLoader => $loader)
        ->withPreset(Name::of('smuggled'), $smuggled);
    $project = Scratch::directory();
    Scratch::write($project, 'gate.smuggled', '');
    Scratch::write($project, 'preset.json', '{"preset": "smuggled", "runner": "pest"}');
    $effective = new Effective(
        $project,
        Registered::config($extensions, static fn(): bool => false),
        new Detected(Directory::at($project), Directory::at(sprintf('%s/vendor', $project))),
        new DateTimeImmutable(Configs::NOW),
    );
    $settings = $effective->settings(CommandLine::nothing()->withConfig($config)->firstPartyOnly());
    $paths = array_map(static fn(string $problem): string => explode(': expected ', $problem)[0], Configs::problems($settings));

    expect($paths)->toBe(array_map(
        static fn(string $path): string => sprintf($at, $path),
        ['trees[0].reason', 'shards.max', 'shards', 'timeouts.seconds', 'ignores.entries[0].reason'],
    ));
})->with([
    'a loader' => ['gate.smuggled', '%s'],
    'a preset' => ['preset.json', 'preset: smuggled sets %s'],
]);

it('says whether the config file or the command line chooses the runner, rather than zero-config', function () use (
    $effective,
    $nothing,
): void {
    $withholding = Scratch::directory();
    Scratch::write($withholding, 'mutation-gate.json', '{"runner": {"withhold": ["DEPLOY_*"]}}');
    $choosing = Scratch::directory();
    Scratch::write($choosing, 'mutation-gate.json', '{"runner": "pest"}');
    $unreadable = Scratch::directory();
    Scratch::write($unreadable, 'mutation-gate.json', '{');

    expect($effective(Tree::at('tests/Fixtures/Projects/TwoRunners'))->choosesRunner($nothing()))->toBeFalse()
        ->and($effective(Tree::at('tests/Fixtures/Projects/TwoRunners'))
            ->choosesRunner(CommandLine::nothing()->withRunner('pest')))->toBeTrue()
        ->and($effective($withholding)->choosesRunner($nothing()))->toBeFalse()
        ->and($effective($choosing)->choosesRunner($nothing()))->toBeTrue()
        ->and($effective($unreadable)->choosesRunner($nothing()))->toBeFalse();
});

it('reads what decides how the gate runs in the config a copy of the config file sets, the command line over it', function () use ($effective): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"runner": "pest", "trees": [{"path": "src", "floor": 90}]}');
    Scratch::write($project, '.mutation-gate/base/mutation-gate.json', '{"runner": "pest", "trees": [{"path": "src", "floor": 80}]}');
    Scratch::write($project, '.mutation-gate/moved/mutation-gate.json', '{"runner": "pest", "trees": [{"path": "lib"}]}');
    $line = CommandLine::nothing()->withBudget('5m');
    $now = $effective($project)->settings($line);
    $copy = static fn(string $directory): ConfigFile => ConfigFile::copyOf(
        Path::of(sprintf('%s/%s/mutation-gate.json', $project, $directory)),
        Path::of(sprintf('%s/mutation-gate.json', $project)),
        Path::of($project),
    );

    expect($effective($project)->decidingOf($line, $copy('.mutation-gate/base')))
        ->toBe($now instanceof Settings ? Canonical::decidingIn($now) : $now)
        ->and($effective($project)->decidingOf($line, $copy('.mutation-gate/moved')))
        ->not->toBe($now instanceof Settings ? Canonical::decidingIn($now) : $now);
});

it('cannot read the config a copy sets where the copy is not a config', function () use ($effective): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.json', '{"runner": "pest"}');
    Scratch::write($project, '.mutation-gate/base/mutation-gate.json', '{"runner": 3}');

    expect($effective($project)->decidingOf(CommandLine::nothing(), ConfigFile::copyOf(
        Path::of(sprintf('%s/.mutation-gate/base/mutation-gate.json', $project)),
        Path::of(sprintf('%s/mutation-gate.json', $project)),
        Path::of($project),
    )))->toBeInstanceOf(Invalid::class);
});

it('reads what the config file reads beside itself, none without one, and names nothing where it cannot find or read the file', function () use ($effective): void {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', "<?php\n\nrequire_once 'shared.php';\n\nreturn NightWorksIO\\MutationGate\\Config\\Gate::configure();\n");
    Scratch::write($project, 'gate.toml', '');
    $bare = Scratch::directory();
    $two = Scratch::directory();
    Scratch::write($two, 'mutation-gate.php', '<?php');
    Scratch::write($two, 'mutation-gate.json', '{}');
    $toml = $effective($project)->reads(CommandLine::nothing()->withConfig('gate.toml'));

    expect($effective($project)->reads(CommandLine::nothing()))->toEqual(ConfigReads::named(Path::of('shared.php')))
        ->and($effective($bare)->reads(CommandLine::nothing()))->toEqual(ConfigReads::none())
        ->and($effective($two)->reads(CommandLine::nothing())->unnamedBecause())
        ->toBe('More than one config file is here: mutation-gate.php, mutation-gate.json. Keep one, or name one with --config.')
        ->and($toml->unnamedBecause())->toBe(sprintf('No config loader reads %s/gate.toml. Name a mutation-gate.php, .json, .yaml, .yml or .neon file.', $project));
});

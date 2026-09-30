<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Cli\Config\Php;
use NightWorksIO\MutationGate\Core\Config\BuiltinCiPlan;
use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A config as the `mutation-gate.php` that writes it. */
$php = static function (array $config): string {
    $layer = Configs::layer($config);

    return $layer instanceof Layer ? Php::render($layer->php(ProjectRoot::origin())) : implode(', ', Configs::problems($layer));
};

/** The effective config of the `mutation-gate.php` a config's effective config is written as. */
$roundTrip = static function (Settings $settings): string {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', Php::render($settings->effective()->php(ProjectRoot::origin())));
    $layer = new PhpConfig()->load(
        ConfigFile::at(Path::of(sprintf('%s/mutation-gate.php', $project)), Path::of($project)),
    );
    $read = $layer instanceof Layer ? Settings::settled($layer, new DateTimeImmutable(Configs::NOW)) : $layer;

    return $read instanceof Settings ? Configs::effective($read) : implode(', ', Configs::problems($read));
};

it('writes a config that reads back into the same effective config', function (array $config) use ($roundTrip): void {
    $settings = Configs::settings($config);

    expect($roundTrip($settings))->toBe(Configs::effective($settings));
})->with([
    'every setting at its default' => [['runner' => 'pest']],
    'every setting away from its default' => [[
        'extensions' => ['Acme\\GateSlack\\SlackExtension'],
        'preset' => ['laravel', 'acme'],
        'runner' => 'infection',
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app', 'lib']]],
        'trees' => [
            ['path' => 'app/Domain', 'floor' => 100],
            ['path' => 'app/Http', 'floor' => 83.419],
            ['path' => 'app/Generated', 'floor' => 0, 'reason' => 'Generated on every build'],
            ['path' => 'app/Legacy'],
        ],
        'newCode' => ['floor' => 90],
        'uncovered' => 'exclude',
        'baseline' => ['path' => 'build/baseline.json', 'improvement' => 'report'],
        'packages' => ['packages/*'],
        'reach' => ['everything' => ['config/**', 'routes/**']],
        'holds' => ['hotPath' => 1],
        'shards' => ['seconds' => 900, 'max' => 8],
        'costs' => ['secondsPerLine' => ['' => 0.25, 'src/Legacy' => 2]],
        'ci' => [
            'plan' => 'gitlab',
            'defaultBranch' => 'trunk',
            'gitlab' => ['template' => '.gitlab/gate.yml'],
            'buildkite' => ['step' => ['agents' => ['queue' => 'mutation'], 'soft_fail' => true, 'tags' => ['a', 'b']]],
        ],
        'proofs' => [
            'store' => ['use' => 's3', 'with' => ['bucket' => 'proofs', 'endpoint' => 'https://r2.example.com']],
            'ignore' => ['docs/**'],
            'write' => 'never',
        ],
        'budget' => '1h30m',
        'timeouts' => ['mode' => 'unjudged', 'seconds' => 30, 'retries' => 0],
        'flaky' => ['confirmSurvivors' => false],
        'ignores' => [
            'entries' => [
                ['mutant' => '3f9a1c2b7d04', 'reason' => 'Both branches build one list', 'expires' => '2026-12-29'],
                [
                    'path' => 'src/Log/**',
                    'mutator' => 'MethodCallRemoval',
                    'reason' => 'Logging is asserted elsewhere',
                    'expires' => '2026-10-01',
                ],
            ],
            'maxDays' => 90,
            'native' => 'allow',
        ],
        'reports' => [
            ['use' => 'sarif', 'path' => 'build/mutation.sarif'],
            ['use' => 'Acme\\GateSlack\\SlackReporter', 'with' => ['channel' => '#ci', 'mentions' => ['@ops']]],
            ['use' => 'Acme\\Html\\Reporter', 'path' => 'build/site'],
        ],
        'badge' => ['colors' => ['green' => 95]],
        'pest' => ['patch' => true, 'canary' => 'canary'],
        'staticCheck' => ['tool' => 'phpstan', 'config' => 'phpstan.dist.neon'],
        'local' => ['watchBudget' => '2m', 'prePushBudget' => '90s'],
    ]],
    'adapters another extension registers' => [[
        'runner' => ['use' => 'acme', 'with' => ['workers' => 4]],
        'treeSource' => ['use' => 'Acme\\Trees', 'with' => ['depth' => 2]],
        'ci' => ['plan' => ['use' => 'jenkins', 'with' => ['label' => 'php']]],
        'proofs' => ['store' => ['use' => 'redis', 'with' => ['dsn' => 'redis://cache']]],
    ]],
    'the settings ADR-0013 to ADR-0016 declare' => [[
        'runner' => ['use' => 'pest', 'withhold' => ['DEPLOY_*', 'COMPOSER_AUTH']],
        'trees' => [['path' => 'src', 'exclude' => ['src/Legacy/**', 'src/Generated/*.php']]],
        'shards' => ['target' => '20m', 'setup' => '3m'],
        'costs' => ['perRunnerMinute' => ['amount' => 0.008, 'currency' => 'USD']],
        'ci' => ['check' => 'gate / verdict', 'buildkite' => ['definition' => '.buildkite/mutation.yml']],
        'proofs' => ['store' => ['use' => 's3', 'with' => [
            'bucket' => 'proofs',
            'prefix' => 'gate',
            'region' => 'eu-west-1',
            'publicUrl' => 'https://proofs.example.com',
        ]]],
        'tests' => ['order' => 'runner'],
        'equivalence' => ['static' => false],
    ]],
    'the other built-in adapters' => [[
        'runner' => 'pest',
        'treeSource' => 'composer',
        'ci' => ['plan' => 'github'],
        'proofs' => ['store' => ['use' => 'directory', 'with' => ['path' => 'build/proofs']]],
        'reports' => [['use' => 'json', 'path' => 'build/mutation.json']],
    ]],
]);

it('writes each built-in CI plan by the builder method of its name, which reads back as that plan', function (
    BuiltinCiPlan $plan,
) use ($php, $roundTrip): void {
    $config = ['runner' => 'pest', 'ci' => ['plan' => $plan->value]];
    $settings = Configs::settings($config);

    expect($php($config))->toContain(sprintf('Ci::%s()', $plan->value))
        ->and($roundTrip($settings))->toBe(Configs::effective($settings));
})->with(BuiltinCiPlan::cases());

it('writes a layer that reads back into the same layer', function () use ($php): void {
    $config = [
        'runner' => ['withhold' => ['DEPLOY_*']],
        'flaky' => ['confirmSurvivors' => true],
        'tests' => ['order' => 'killers-first'],
        'equivalence' => ['static' => true],
        'pest' => ['patch' => false],
    ];
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', $php($config));
    $layer = new PhpConfig()->load(
        ConfigFile::at(Path::of(sprintf('%s/mutation-gate.php', $project)), Path::of($project)),
    );

    expect($layer instanceof Layer ? Configs::decoded($layer) : Configs::problems($layer))->toBe($config);
});

it('writes the config init writes as a builder a person reads', function () use ($php): void {
    expect($php([
        'preset' => 'laravel',
        'runner' => 'pest',
        'trees' => [['path' => 'app'], ['path' => 'app/Generated', 'floor' => 0, 'reason' => 'Generated']],
    ]))->toBe(<<<'PHP'
        <?php

        declare(strict_types=1);

        use NightWorksIO\MutationGate\Config\Gate;
        use NightWorksIO\MutationGate\Config\Preset;
        use NightWorksIO\MutationGate\Config\Runner;
        use NightWorksIO\MutationGate\Config\Tree;

        return Gate::configure()
            ->preset(Preset::laravel())
            ->runner(Runner::pest())
            ->trees(
                Tree::at('app'),
                Tree::at('app/Generated', floor: 0, because: 'Generated'),
            );

        PHP);
});

it('writes an empty list of trees, which declares no tree at all', function () use ($php): void {
    expect($php(['runner' => 'pest', 'trees' => []]))->toContain("\n    ->trees()");
});

it('writes every other setting in one call to with', function () use ($php): void {
    expect($php(['runner' => 'pest', 'budget' => '15m', 'shards' => ['max' => 4]]))
        ->toContain(<<<'PHP'
            return Gate::configure()
                ->runner(Runner::pest())
                ->with(
                    Shards::max(4),
                    Budget::of('15m'),
                );
            PHP);
});

it('writes a report that writes no file with uses, and one that writes a file with writing', function () use ($php): void {
    expect($php(['runner' => 'pest', 'reports' => [
        ['use' => 'sarif', 'path' => 'build/m.sarif'],
        ['use' => 'acme', 'with' => ['channel' => '#ci']],
        ['use' => 'acme', 'path' => 'build/a.txt', 'with' => ['channel' => '#ci']],
        ['use' => 'acme', 'path' => 'build/b.txt'],
    ]]))
        ->toContain("Report::sarif('build/m.sarif'),")
        ->toContain("Report::uses('acme', Option::of('channel', '#ci')),")
        ->toContain("Report::writing('acme', 'build/a.txt', Option::of('channel', '#ci')),")
        ->toContain("Report::writing('acme', 'build/b.txt'),");
});

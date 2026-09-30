<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Php\PhpConfig;
use NightWorksIO\MutationGate\Cli\Config\Php;
use NightWorksIO\MutationGate\Core\Config\Document;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The effective config a config file shows, read back from the `mutation-gate.php` it is written as. */
$roundTrip = static function (string $effective): string {
    $project = Scratch::directory();
    Scratch::write($project, 'mutation-gate.php', Php::render(Configs::document($effective)));
    $document = new PhpConfig()->load(Path::of(sprintf('%s/mutation-gate.php', $project)));

    return $document instanceof Document ? Configs::settings($document->json())->effective() : $document->why();
};

it('writes a config that reads back into the same effective config', function (array $config) use ($roundTrip): void {
    $effective = Configs::settings($config)->effective();

    expect($roundTrip($effective))->toBe($effective);
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
        'local' => ['watchBudget' => '2m', 'prePushBudget' => '90s'],
    ]],
    'adapters another extension registers' => [[
        'runner' => ['use' => 'acme', 'with' => ['workers' => 4]],
        'treeSource' => ['use' => 'Acme\\Trees', 'with' => ['depth' => 2]],
        'ci' => ['plan' => ['use' => 'jenkins', 'with' => ['label' => 'php']]],
        'proofs' => ['store' => ['use' => 'redis', 'with' => ['dsn' => 'redis://cache']]],
    ]],
    'the other built-in adapters' => [[
        'runner' => 'pest',
        'treeSource' => 'composer',
        'ci' => ['plan' => 'github'],
        'proofs' => ['store' => ['use' => 'directory', 'with' => ['path' => 'build/proofs']]],
        'reports' => [['use' => 'json', 'path' => 'build/mutation.json']],
    ]],
]);

it('writes the config init writes as a builder a person reads', function (): void {
    expect(Php::render(Configs::document([
        'preset' => 'laravel',
        'runner' => 'pest',
        'trees' => [['path' => 'app'], ['path' => 'app/Generated', 'floor' => 0, 'reason' => 'Generated']],
    ])))->toBe(<<<'PHP'
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

it('writes an empty list of trees, which declares no tree at all', function (): void {
    expect(Php::render(Configs::document(['runner' => 'pest', 'trees' => []])))->toContain("\n    ->trees()");
});

it('writes every other setting in one call to with', function (): void {
    expect(Php::render(Configs::document(['runner' => 'pest', 'budget' => '15m', 'shards' => ['max' => 4]])))
        ->toContain(<<<'PHP'
            return Gate::configure()
                ->runner(Runner::pest())
                ->with(
                    Shards::max(4),
                    Budget::of('15m'),
                );
            PHP);
});

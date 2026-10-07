<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Reach as ReachSetting;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Price;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Report;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\SettingsCases;

it('reads tree excludes, the shard target and setup, the price, the test order and the equivalence check', function (
): void {
    $settings = Configs::settings([
        'runner' => 'pest',
        'trees' => [['path' => 'src', 'exclude' => ['src/Legacy/**', 'src/Generated/*.php']]],
        'shards' => ['target' => '20m', 'setup' => '3m'],
        'costs' => ['perRunnerMinute' => ['amount' => 0.008, 'currency' => 'USD']],
        'proofs' => ['store' => ['use' => 's3', 'with' => [
            'bucket' => 'proofs',
            'publicUrl' => 'https://proofs.example.com',
        ]]],
        'tests' => ['order' => 'runner'],
        'equivalence' => ['static' => false],
    ]);
    $trees = $settings->floors()->trees();
    $price = $settings->shards()->perRunnerMinute();

    expect($trees instanceof Absent ? [] : [...[...$trees][0]->exclude()])
        ->toEqual([Glob::of('src/Legacy/**'), Glob::of('src/Generated/*.php')])
        ->and($settings->shards()->target())->toEqual(Seconds::of(1200))
        ->and($settings->shards()->setup())->toEqual(Seconds::of(180))
        ->and($price instanceof Price ? [$price->amount(), $price->currency()] : [])->toBe([0.008, 'USD'])
        ->and(Configs::shown($settings, 'proofs', 'store', 'with', 'publicUrl'))->toBe('https://proofs.example.com')
        ->and($settings->triage()->order())->toBe(TestOrder::Runner)
        ->and($settings->ignores()->staticEquivalence())->toBeFalse();
});

it('excludes nothing, cuts by seconds, prices nothing, puts killers first and checks equivalence by default', function (
): void {
    $settings = Configs::settings(['runner' => 'pest', 'trees' => [['path' => 'src']]]);
    $trees = $settings->floors()->trees();

    expect($trees instanceof Absent ? ['no trees'] : [...[...$trees][0]->exclude()])->toBe([])
        ->and($settings->shards()->target())->toEqual(Absent::setting())
        ->and($settings->shards()->setup())->toEqual(Seconds::of(60))
        ->and($settings->shards()->perRunnerMinute())->toEqual(Absent::setting())
        ->and($settings->triage()->order())->toBe(TestOrder::KillersFirst)
        ->and($settings->ignores()->staticEquivalence())->toBeTrue();
});

it('refuses a shard count set two ways, and a price, a public URL, an order or an exclude written wrong', function (
    array $config,
    array $problems,
): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', ...$config])))->toBe($problems);
})->with([
    'a shard count set two ways' => [
        ['shards' => ['seconds' => 600, 'target' => '20m']],
        ['shards: expected either seconds or target, but not both'],
    ],
    'a price without its currency' => [
        ['costs' => ['perRunnerMinute' => ['amount' => -1]]],
        [
            'costs.perRunnerMinute.amount: expected a number of at least 0, got -1',
            'costs.perRunnerMinute.currency: expected a currency, such as EUR, got nothing',
        ],
    ],
    'a public URL that is not https' => [
        ['proofs' => ['store' => ['use' => 's3', 'with' => ['bucket' => 'b', 'publicUrl' => 'http://proofs']]]],
        ['proofs.store.with.publicUrl: expected an https:// URL with no user, query or fragment, got "http://proofs"'],
    ],
    'a public URL that names a user' => [
        ['proofs' => ['store' => ['use' => 's3', 'with' => ['bucket' => 'b', 'publicUrl' => 'https://reader:secret@proofs']]]],
        ['proofs.store.with.publicUrl: expected an https:// URL with no user, query or fragment, got "https://reader:secret@proofs"'],
    ],
    'a public URL with a query' => [
        ['proofs' => ['store' => ['use' => 's3', 'with' => ['bucket' => 'b', 'publicUrl' => 'https://proofs/ledgers?signed=1']]]],
        ['proofs.store.with.publicUrl: expected an https:// URL with no user, query or fragment, got "https://proofs/ledgers?signed=1"'],
    ],
    'a public URL with a fragment' => [
        ['proofs' => ['store' => ['use' => 's3', 'with' => ['bucket' => 'b', 'publicUrl' => 'https://proofs/ledgers#main']]]],
        ['proofs.store.with.publicUrl: expected an https:// URL with no user, query or fragment, got "https://proofs/ledgers#main"'],
    ],
    'a test order the gate does not know' => [
        ['tests' => ['order' => 'random']],
        ['tests.order: expected "killers-first" or "runner", got "random"'],
    ],
    'an exclude that is not a list of globs' => [
        ['trees' => [['path' => 'src', 'exclude' => 'src/Legacy']]],
        ['trees[0].exclude: expected a list, got "src/Legacy"'],
    ],
]);

it('reads the file reporters, the chat reporters with their variables, and OpenTelemetry', function (): void {
    $settings = Configs::settings(['runner' => 'pest', 'reports' => [
        ['use' => 'tests', 'path' => 'build/tests.json'],
        ['use' => 'kill-matrix', 'path' => 'build/kills.csv'],
        ['use' => 'gitlab', 'path' => 'gl-code-quality.json'],
        ['use' => 'slack'],
        ['use' => 'discord', 'with' => ['urlEnv' => 'TEAM_DISCORD']],
        ['use' => 'webhook'],
        ['use' => 'otlp', 'with' => ['endpoint' => 'https://otel.example.com']],
    ]]);

    expect(Configs::shown($settings, 'reports'))->toBe([
        ['use' => 'tests', 'path' => 'build/tests.json'],
        ['use' => 'kill-matrix', 'path' => 'build/kills.csv'],
        ['use' => 'gitlab', 'path' => 'gl-code-quality.json'],
        ['use' => 'slack', 'with' => ['urlEnv' => 'MUTATION_GATE_SLACK_URL']],
        ['use' => 'discord', 'with' => ['urlEnv' => 'TEAM_DISCORD']],
        [
            'use' => 'webhook',
            'with' => ['urlEnv' => 'MUTATION_GATE_WEBHOOK_URL', 'secretEnv' => 'MUTATION_GATE_WEBHOOK_SECRET'],
        ],
        ['use' => 'otlp', 'with' => ['endpoint' => 'https://otel.example.com']],
    ])->and(array_map(static fn(Report $report): string => $report->reporter()->options()->written()->line(), [...$settings->reports()]))
        ->toBe([
            '{}',
            '{}',
            '{}',
            '{"urlEnv":"MUTATION_GATE_SLACK_URL"}',
            '{"urlEnv":"TEAM_DISCORD"}',
            '{"urlEnv":"MUTATION_GATE_WEBHOOK_URL","secretEnv":"MUTATION_GATE_WEBHOOK_SECRET"}',
            '{"endpoint":"https://otel.example.com"}',
        ]);
});

it('refuses a webhook URL in the config, and a path a reporter cannot take or needs', function (
    array $report,
    string $problem,
): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'reports' => [$report]])))->toBe([$problem]);
})->with([
    'a webhook URL in the config' => [
        ['use' => 'slack', 'with' => ['url' => 'https://hooks.slack.com/x']],
        'reports[0].with.url: expected no url: a webhook URL is a credential; set MUTATION_GATE_SLACK_URL, '
        . 'or name another variable in urlEnv',
    ],
    'a path for a reporter that writes no file' => [
        ['use' => 'otlp', 'path' => 'build/otlp.json'],
        'reports[0].path: expected nothing, as otlp writes no file, got "build/otlp.json"',
    ],
    'no path for a reporter that writes a file' => [
        ['use' => 'kill-matrix'],
        'reports[0].path: expected a path, got nothing',
    ],
]);

it('refuses a canary group name with whitespace, and a store endpoint that is not a URL', function (): void {
    expect(Configs::problems(Configs::validated([
        'runner' => 'pest',
        'pest' => ['canary' => 'mutation canary'],
        'proofs' => ['store' => ['use' => 's3', 'with' => ['bucket' => 'b', 'endpoint' => 'minio.test:9000']]],
    ])))->toBe([
        'proofs.store.with.endpoint: expected an http:// or https:// URL, got "minio.test:9000"',
        'pest.canary: expected a group name, with no whitespace, got "mutation canary"',
    ])->and(Configs::validated([
        'runner' => 'pest',
        'pest' => ['canary' => 'mutation-canary'],
        'proofs' => ['store' => ['use' => 's3', 'with' => ['bucket' => 'b', 'endpoint' => 'http://minio.test:9000']]],
    ]))->toBeInstanceOf(Settings::class);
});

it('reads a key that reads as a number as any other: unknown where nothing declares it, and a name in a map', function (): void {
    $colors = Configs::validated('{"runner": "pest", "reports": [{"use": "badge", "path": "b.svg", "with": {"colors": {"12": 50}}}]}');

    expect(Configs::problems(Configs::validated('{"runner": "pest", "12": 1}')))->toBe(['12: unknown key'])
        ->and(Configs::validated('{"runner": "pest", "costs": {"secondsPerLine": {"12": 2}}}'))->toBeInstanceOf(Settings::class)
        ->and($colors)->toBeInstanceOf(Settings::class);
});

it('names each CI\'s pipeline file in a config written as PHP', function (): void {
    expect(Configs::valid(SettingsCases::EVERYTHING)->php(ProjectRoot::origin())->code())
        ->toContain("Pipeline::azureDefinition('ci/azure.yml')")
        ->toContain("Pipeline::bitbucketDefinition('ci/bitbucket.yml')")
        ->toContain("Pipeline::jenkinsDefinition('ci/Jenkinsfile')")
        ->toContain('StaticCheck::seconds(45)')
        ->toContain('Survivors::firstAtMost(5)');
});

it('writes how uncovered mutants count and the baseline\'s path and improvement into a config written as PHP', function (array $config, string $with): void {
    expect(Configs::valid(['runner' => 'pest', ...$config])->php(ProjectRoot::origin())->code())
        ->toContain(sprintf("    ->with(\n%s\n    )", $with));
})->with([
    'excluded, at a path, reported' => [
        ['uncovered' => 'exclude', 'baseline' => ['path' => 'build/baseline.json', 'improvement' => 'report']],
        "        Uncovered::excluded(),\n        Baseline::at('build/baseline.json'),\n        Baseline::reportingImprovement(),",
    ],
    'counted, required' => [
        ['uncovered' => 'count', 'baseline' => ['improvement' => 'require']],
        "        Uncovered::counted(),\n        Baseline::requiringImprovement(),",
    ],
]);

it('hands on a name in a map that reads as a number as text, to the cost model and to a config written as PHP', function (): void {
    $json = '{"runner": "pest", "costs": {"secondsPerLine": {"12": 2}}, "badge": {"colors": {"12": 50}}, '
        . '"reports": [{"use": "badge", "path": "b.svg", "with": {"colors": {"12": 50}}}]}';
    $settings = Configs::settings($json);
    $php = Configs::valid($json)->php(ProjectRoot::origin())->code();

    $options = $settings->shards()->costOptions()->object(Key::of('secondsPerLine'));

    expect($options instanceof Options ? $options->number(Key::of('12')) : $options)->toBe(2.0)
        ->and($settings->shards()->secondsPerLine()->forPath(Path::of('12/Money.php')))->toEqual(Seconds::of(2.0))
        ->and($php)->toContain("Shards::secondsPerLine('12', 2)")
        ->and($php)->toContain("Badge::colour('12', 50)")
        ->and($php)->toContain("Option::nested('colors', Option::of('12', 50))");
});

it('reads the gcs and azure stores with their defaults, and refuses names their clouds do not allow, or a key', function (
    array $store,
    array $problems,
): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'proofs' => ['store' => $store]])))->toBe($problems);
})->with([
    'a bucket' => [['use' => 'gcs', 'with' => ['bucket' => 'acme-ledgers.example', 'publicUrl' => 'https://storage.googleapis.com/acme-ledgers']], []],
    'a container' => [['use' => 'azure', 'with' => ['account' => 'acme01', 'container' => 'ledgers-1', 'publicContainer' => 'pub']], []],
    'no bucket' => [['use' => 'gcs'], ['proofs.store.with.bucket: expected a Cloud Storage bucket name, got nothing']],
    'a bucket that leaves its path' => [
        ['use' => 'gcs', 'with' => ['bucket' => '../other?x']],
        ['proofs.store.with.bucket: expected a Cloud Storage bucket name, got "../other?x"'],
    ],
    'a region that leaves its host' => [
        ['use' => 's3', 'with' => ['bucket' => 'ledgers', 'region' => 'us-east-1@evil.example']],
        ['proofs.store.with.region: expected a region, got "us-east-1@evil.example"'],
    ],
    'an account that leaves its host' => [
        ['use' => 'azure', 'with' => ['account' => 'evil.example/x', 'container' => 'ledgers']],
        ['proofs.store.with.account: expected a storage account name, got "evil.example/x"'],
    ],
    'a container Azure does not allow' => [
        ['use' => 'azure', 'with' => ['account' => 'acme', 'container' => 'ledgers--', 'publicContainer' => 'A']],
        [
            'proofs.store.with.container: expected a container name, got "ledgers--"',
            'proofs.store.with.publicContainer: expected a container name, got "A"',
        ],
    ],
    'an account key' => [
        ['use' => 'azure', 'with' => ['account' => 'acme', 'container' => 'ledgers', 'accountKey' => 'abc==']],
        ['proofs.store.with.accountKey: unknown key, did you mean account?'],
    ],
]);

it('defaults the gcs and azure stores\' prefix to the gate\'s name', function (): void {
    expect(Configs::shown(Configs::settings(['runner' => 'pest', 'proofs' => ['store' => ['use' => 'gcs', 'with' => ['bucket' => 'acme']]]]), 'proofs', 'store', 'with', 'prefix'))
        ->toBe('mutation-gate')
        ->and(Configs::shown(Configs::settings(['runner' => 'pest', 'proofs' => ['store' => ['use' => 'azure', 'with' => ['account' => 'acme', 'container' => 'c1c']]]]), 'proofs', 'store', 'with', 'prefix'))
        ->toBe('mutation-gate');
});

it('measures again only what moved unless the config says to measure every test, a later layer winning', function (): void {
    expect(Configs::settings(['runner' => 'pest'])->proofs()->incrementalCoverage())->toBeTrue()
        ->and(Configs::settings(['runner' => 'pest', 'coverage' => ['incremental' => false]])->proofs()->incrementalCoverage())->toBeFalse()
        ->and(Configs::settings(['runner' => 'pest', 'coverage' => ['incremental' => true]])->proofs()->incrementalCoverage())->toBeTrue()
        ->and(Configs::problems(Configs::validated(['runner' => 'pest', 'coverage' => ['incremental' => 'yes']])))
        ->toBe(['coverage.incremental: expected true or false, got "yes"']);
});

it('re-checks the comment\'s count of survivors first by default, as many as set, and refuses fewer than none', function (): void {
    expect(Configs::settings(['runner' => 'pest'])->triage()->survivorsFirst()->most())->toBe(20)
        ->and(Configs::settings(['runner' => 'pest', 'survivorsFirst' => ['max' => 0]])->triage()->survivorsFirst()->isOff())
        ->toBeTrue()
        ->and(Configs::problems(Configs::validated(['runner' => 'pest', 'survivorsFirst' => ['max' => -1]])))
        ->toBe(['survivorsFirst.max: expected an integer of at least 0, got -1']);
});

it('reads how the runner\'s workers start, forking where no layer says, and refuses any other word', function (): void {
    $fresh = Configs::settings(['runner' => ['use' => 'phpunit', 'workers' => 'fresh']]);

    expect($fresh->runner()->workers())->toBe(Workers::Fresh)
        ->and(Configs::settings(['runner' => 'phpunit'])->runner()->workers())->toBe(Workers::Fork)
        ->and(Configs::shown($fresh, 'runner'))->toBe(['use' => 'phpunit', 'memory' => '1G', 'workers' => 'fresh'])
        ->and(Configs::problems(Configs::validated(['runner' => ['use' => 'phpunit', 'workers' => 'warm']])))
        ->toBe(['runner.workers: expected "fork" or "fresh", got "warm"']);
});

it('reads whether a run given no mode considers every unit, changed since last-passed by default, and writes it into a config written as PHP', function (array $config, bool $full, string $call): void {
    $php = Configs::valid(['runner' => 'pest', ...$config])->php(ProjectRoot::origin())->code();

    expect(Configs::settings(['runner' => 'pest', ...$config])->reach()->isFullByDefault())->toBe($full)
        ->and($call === '' ? ! str_contains($php, 'ByDefault()') : str_contains($php, $call))->toBeTrue();
})->with([
    'left out' => [[], false, ''],
    'every unit' => [['run' => ['full' => true]], true, 'Reach::fullByDefault()'],
    'what changed' => [['run' => ['full' => false]], false, 'Reach::changedByDefault()'],
]);

it('writes run.full from the PHP config\'s builders as the JSON a file writes', function (): void {
    expect(ReachSetting::fullByDefault()->written())->toEqual(Json::at('run.full', value: true))
        ->and(ReachSetting::changedByDefault()->written())->toEqual(Json::at('run.full', value: false));
});

it('reads timeouts.tighter, each key the standard where it is left out, and refuses a name no runner gives a mutator', function (): void {
    $floor = Configs::settings(['runner' => 'pest', 'timeouts' => ['tighter' => ['floor' => 5]]])->triage()->tighter();
    $mutators = Configs::settings(['runner' => 'pest', 'timeouts' => ['tighter' => ['mutators' => ['Foreach_']]]])->triage()->tighter();

    expect([$floor->floor(), [...$floor]])->toEqual([Seconds::of(5.0), TighterSilence::MUTATORS])
        ->and([$mutators->floor(), [...$mutators]])->toEqual([Seconds::of(7.0), ['Foreach_']])
        ->and(Configs::settings(['runner' => 'pest'])->triage()->tighter())->toEqual(TighterSilence::standard())
        ->and(Configs::problems(Configs::validated(['timeouts' => ['tighter' => ['mutators' => ['default/RemoveArrayItem'], 'floor' => 0]]])))
        ->toHaveCount(2);
});

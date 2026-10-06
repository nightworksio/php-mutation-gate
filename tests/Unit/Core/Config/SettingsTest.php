<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\IgnoredMutant;
use NightWorksIO\MutationGate\Core\Config\IgnoredPattern;
use NightWorksIO\MutationGate\Core\Config\Improvement;
use NightWorksIO\MutationGate\Core\Config\Key;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Price;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Report;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Runner\Workers;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Time\Budgets;
use NightWorksIO\MutationGate\Core\Time\Day;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Configs;

/** The effective config of `{"runner": "pest"}`: every other setting at its default. */
const DEFAULTS = <<<'JSON'
    {
        "extensions": [],
        "runner": {
            "use": "pest",
            "memory": "1G"
        },
        "mutators": {
            "sets": [],
            "except": []
        },
        "treeSource": {
            "use": "phpunit",
            "with": {
                "fallback": []
            }
        },
        "newCode": {
            "floor": 100
        },
        "uncovered": "count",
        "baseline": {
            "path": "mutation-gate.baseline.json",
            "improvement": "require"
        },
        "packages": [],
        "reach": {
            "everything": []
        },
        "holds": {
            "hotPath": 0.8
        },
        "shards": {
            "seconds": 600,
            "max": 20,
            "setup": "1m"
        },
        "costs": {
            "secondsPerLine": {
                "": 0.2
            }
        },
        "ci": {
            "check": "mutation / verdict",
            "trustMergedPullRequests": false,
            "gitlab": {
                "template": ".gitlab/mutation-gate.yml"
            },
            "buildkite": {
                "step": {},
                "definition": ".buildkite/pipeline.yml"
            },
            "azure": {
                "definition": "azure-pipelines.yml"
            },
            "bitbucket": {
                "definition": "bitbucket-pipelines.yml"
            },
            "jenkins": {
                "definition": "Jenkinsfile"
            }
        },
        "proofs": {
            "store": {
                "use": "directory",
                "with": {
                    "path": ".mutation-gate/ledger"
                }
            },
            "ignore": [],
            "write": "auto"
        },
        "coverage": {
            "incremental": true
        },
        "timeouts": {
            "mode": "confirm",
            "seconds": 10,
            "retries": 20
        },
        "flaky": {
            "confirmSurvivors": true
        },
        "tests": {
            "order": "killers-first"
        },
        "survivorsFirst": {
            "max": 20
        },
        "ignores": {
            "entries": [],
            "native": "refuse"
        },
        "equivalence": {
            "static": true
        },
        "reports": [],
        "badge": {
            "colors": {
                "brightgreen": 90,
                "green": 80,
                "yellow": 70,
                "orange": 60
            }
        },
        "pest": {
            "patch": false,
            "canary": "mutation-canary"
        },
        "staticCheck": {
            "tool": "auto",
            "seconds": 60
        },
        "local": {
            "watchBudget": "1m",
            "prePushBudget": "5m"
        }
    }
    JSON;

/** A config that sets every setting away from its default. */
const EVERYTHING = [
    '$schema' => 'resources/mutation-gate.schema.json',
    'extensions' => ['Acme\\GateSlack\\SlackExtension'],
    'preset' => ['laravel', 'acme'],
    'runner' => ['use' => 'infection', 'with' => [], 'withhold' => ['DEPLOY_*', 'COMPOSER_AUTH'], 'memory' => '512m', 'workers' => 'fresh'],
    'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app', 'lib']]],
    'mutators' => ['sets' => ['acme', 'acme-auth'], 'except' => ['acme/RemoveAudit']],
    'trees' => [
        ['path' => 'app/Domain', 'floor' => 100],
        ['path' => 'app/Http', 'floor' => 83.419],
        ['path' => './app/Generated/', 'floor' => 0, 'reason' => 'Generated on every build'],
        ['path' => 'app/Legacy'],
    ],
    'newCode' => ['floor' => 90],
    'security' => ['floor' => 97.5],
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
        'check' => 'gate / verdict',
        'trustMergedPullRequests' => true,
        'gitlab' => ['template' => '.gitlab/gate.yml'],
        'buildkite' => ['step' => ['agents' => ['queue' => 'mutation']], 'definition' => '.buildkite/mutation.yml'],
        'azure' => ['definition' => 'ci/azure.yml'],
        'bitbucket' => ['definition' => 'ci/bitbucket.yml'],
        'jenkins' => ['definition' => 'ci/Jenkinsfile'],
    ],
    'proofs' => [
        'store' => ['use' => 's3', 'with' => ['bucket' => 'proofs', 'endpoint' => 'https://r2.example.com']],
        'ignore' => ['docs/**'],
        'write' => 'never',
    ],
    'coverage' => ['incremental' => false],
    'budget' => '1h30m',
    'timeouts' => ['mode' => 'unjudged', 'seconds' => 30, 'retries' => 0],
    'flaky' => ['confirmSurvivors' => false],
    'survivorsFirst' => ['max' => 5],
    'ignores' => [
        'entries' => [
            ['mutant' => '3f9a1c2b7d04', 'reason' => 'Both branches build the same list', 'expires' => '2026-12-29'],
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
        ['use' => 'Acme\\GateSlack\\SlackReporter', 'with' => ['channel' => '#ci']],
    ],
    'badge' => ['colors' => ['green' => 95]],
    'pest' => ['patch' => true, 'canary' => 'canary'],
    'staticCheck' => ['tool' => 'phpstan', 'config' => 'phpstan.dist.neon', 'seconds' => 45],
    'local' => ['watchBudget' => '2m', 'prePushBudget' => '90s'],
];

it('fills every setting a config leaves out with its default', function (): void {
    expect(Configs::effective(Configs::settings(['runner' => 'pest'])))->toBe(DEFAULTS);
});

it('reads the effective config back into the same settings', function (array|string $config): void {
    $settings = Configs::settings($config);

    expect(Configs::settings(Configs::effective($settings)))->toEqual($settings);
})->with([
    'every setting' => [EVERYTHING],
    'badge colours of its own' => [['runner' => 'pest', 'badge' => ['colors' => ['green' => 95]]]],
    'no badge colours' => [['runner' => 'pest', 'badge' => ['colors' => []]]],
    'a cost per line under one prefix' => [['runner' => 'pest', 'costs' => ['secondsPerLine' => ['src/' => 1]]]],
]);

it('answers each map as the effective config shows it', function (): void {
    $colors = Configs::settings(['runner' => 'pest', 'badge' => ['colors' => ['green' => 95]]]);
    $costs = Configs::settings(['runner' => 'pest', 'costs' => ['secondsPerLine' => ['src/' => 1]]]);

    expect(iterator_to_array($colors->badge(), preserve_keys: true))->toBe(['green' => 95])
        ->and(Configs::shown($colors, 'badge', 'colors'))->toBe(['green' => 95])
        ->and(iterator_to_array($costs->shards()->secondsPerLine(), preserve_keys: true))->toBe(['' => 0.2, 'src' => 1.0])
        ->and(Configs::shown($costs, 'costs', 'secondsPerLine'))->toBe(['' => 0.2, 'src' => 1]);
});

it('reads the defaults into their types', function (): void {
    $settings = Configs::settings(['runner' => 'pest']);
    $floors = $settings->floors();

    expect([...$settings->extensions()])->toBe([])
        ->and([...$settings->presets()])->toBe([])
        ->and($settings->runner()->choice())
        ->toEqual(Choice::of('pest', Configs::options('{"patch": false, "canary": "mutation-canary"}')))
        ->and($settings->treeSource())->toEqual(Choice::of('phpunit', Configs::options('{"fallback":[]}')))
        ->and($floors->trees())->toEqual(Absent::setting())
        ->and($floors->newCode())->toEqual(Floor::of(100))
        ->and($floors->security())->toEqual(Undeclared::floor())
        ->and($floors->uncovered())->toBe(Uncovered::Count)
        ->and($floors->baseline())->toEqual(Path::of('mutation-gate.baseline.json'))
        ->and($floors->improvement())->toBe(Improvement::Require)
        ->and([...$settings->reach()->packages()])->toBe([])
        ->and([...$settings->reach()->everything()])->toBe([])
        ->and($settings->reach()->hotPaths()->share())->toBe(0.8)
        ->and($settings->shards()->seconds())->toEqual(Seconds::of(600))
        ->and($settings->shards()->max())->toBe(20)
        ->and(iterator_to_array($settings->shards()->secondsPerLine(), preserve_keys: true))->toBe(['' => 0.2])
        ->and($settings->ci()->plan())->toEqual(Absent::setting())
        ->and($settings->ci()->defaultBranch())->toEqual(Absent::setting())
        ->and($settings->ci()->check())->toBe('mutation / verdict')
        ->and($settings->ci()->trustsMergedPullRequests())->toBeFalse()
        ->and($settings->ci()->gitlabTemplate())->toEqual(Path::of('.gitlab/mutation-gate.yml'))
        ->and($settings->ci()->buildkiteStep()->json()->line())->toBe('{}')
        ->and($settings->ci()->buildkiteDefinition())->toEqual(Path::of('.buildkite/pipeline.yml'))
        ->and($settings->ci()->bitbucketDefinition())->toEqual(Path::of('bitbucket-pipelines.yml'))
        ->and($settings->ci()->jenkinsDefinition())->toEqual(Path::of('Jenkinsfile'))
        ->and($settings->runner()->withhold())->toEqual(Withheld::nothing())
        ->and($settings->runner()->memory())->toEqual(MemoryCap::standard())
        ->and($settings->runner()->workers())->toBe(Workers::Fork)
        ->and($settings->proofs()->store())->toEqual(Choice::of('directory', Configs::options('{"path":".mutation-gate/ledger"}')))
        ->and([...$settings->proofs()->ignore()])->toBe([])
        ->and($settings->proofs()->write())->toBe(Writing::Auto)
        ->and($settings->triage()->budget())->toEqual(Unlimited::time())
        ->and($settings->triage()->timeouts())->toBe(TimeoutMode::Confirm)
        ->and($settings->triage()->limit())->toEqual(Seconds::of(10))
        ->and($settings->triage()->retries())->toBe(20)
        ->and($settings->triage()->confirmSurvivors())->toBeTrue()
        ->and([...$settings->ignores()->entries()])->toBe([])
        ->and($settings->ignores()->maxDays())->toEqual(Absent::setting())
        ->and($settings->ignores()->native())->toBe(NativeMarkers::Refuse)
        ->and([...$settings->reports()])->toBe([])
        ->and([...$settings->badge()])
        ->toBe(['brightgreen' => 90, 'green' => 80, 'yellow' => 70, 'orange' => 60])
        ->and($settings->effective()->pest()->patch())->toBeFalse()
        ->and($settings->effective()->pest()->canary())->toEqual(Group::named('mutation-canary'))
        ->and($settings->staticCheck()->tool())->toEqual(Choice::of('auto', Configs::options('{}')))
        ->and($settings->staticCheck()->config())->toEqual(Absent::setting())
        ->and($settings->staticCheck()->seconds())->toEqual(Seconds::of(60))
        ->and($settings->local()->watchBudget())->toEqual(Budgets::standard()->watch())
        ->and($settings->local()->prePushBudget())->toEqual(Budgets::standard()->prePush());
});

it('reads every setting a config writes into its type', function (): void {
    $settings = Configs::settings(EVERYTHING);
    $floors = $settings->floors();
    $trees = $floors->trees();
    $ci = $settings->ci();
    $proofs = $settings->proofs();
    $triage = $settings->triage();

    expect([...$settings->extensions()])->toBe(['Acme\\GateSlack\\SlackExtension'])
        ->and([...$settings->presets()])->toBe(['laravel', 'acme'])
        ->and($settings->runner()->choice())->toEqual(Choice::of('infection', Configs::options('{}')))
        ->and($settings->runner()->withhold())->toEqual(Withheld::of('DEPLOY_*', 'COMPOSER_AUTH'))
        ->and($settings->runner()->memory())->toEqual(MemoryCap::of(512, MemoryUnit::Megabytes))
        ->and($settings->runner()->workers())->toBe(Workers::Fresh)
        ->and($settings->treeSource())->toEqual(Choice::of('phpunit', Configs::options('{"fallback":["app","lib"]}')))
        ->and([...$settings->mutators()->sets()])->toEqual([Name::of('acme'), Name::of('acme-auth')])
        ->and([...$settings->mutators()->except()])->toBe(['acme/RemoveAudit'])
        ->and(array_map(
            static fn(DeclaredTree $tree): array => [$tree->path()->value(), $tree->declared()],
            $trees instanceof Absent ? [] : [...$trees],
        ))->toEqual([
            ['app/Domain', Floor::of(100)],
            ['app/Http', Floor::ofHundredths(8341)],
            ['app/Generated', Exempt::because('Generated on every build')],
            ['app/Legacy', Undeclared::floor()],
        ])
        ->and($floors->newCode())->toEqual(Floor::of(90))
        ->and($floors->security())->toEqual(Floor::of(97.5))
        ->and($floors->uncovered())->toBe(Uncovered::Exclude)
        ->and($floors->baseline())->toEqual(Path::of('build/baseline.json'))
        ->and($floors->improvement())->toBe(Improvement::Report)
        ->and([...$settings->reach()->packages()])->toEqual([Glob::of('packages/*')])
        ->and([...$settings->reach()->everything()])->toEqual([Glob::of('config/**'), Glob::of('routes/**')])
        ->and($settings->reach()->hotPaths()->share())->toBe(1.0)
        ->and($settings->shards()->seconds())->toEqual(Seconds::of(900))
        ->and($settings->shards()->max())->toBe(8)
        ->and(iterator_to_array($settings->shards()->secondsPerLine(), preserve_keys: true))->toBe(['' => 0.25, 'src/Legacy' => 2.0])
        ->and($ci->plan() instanceof Choice ? $ci->plan()->options()->written()->line() : $ci->plan())
        ->toBe('{"template":".gitlab/gate.yml"}')
        ->and($ci->defaultBranch())->toBe('trunk')
        ->and($ci->check())->toBe('gate / verdict')
        ->and($ci->trustsMergedPullRequests())->toBeTrue()
        ->and($ci->gitlabTemplate())->toEqual(Path::of('.gitlab/gate.yml'))
        ->and($ci->buildkiteStep()->json()->line())->toBe('{"agents":{"queue":"mutation"}}')
        ->and($ci->buildkiteDefinition())->toEqual(Path::of('.buildkite/mutation.yml'))
        ->and($ci->bitbucketDefinition())->toEqual(Path::of('ci/bitbucket.yml'))
        ->and($ci->jenkinsDefinition())->toEqual(Path::of('ci/Jenkinsfile'))
        ->and($proofs->store())->toEqual(Choice::of(
            's3',
            Configs::options('{"bucket":"proofs","prefix":"mutation-gate","region":"us-east-1","endpoint":"https://r2.example.com","insecureEndpoint":false}'),
        ))
        ->and([...$proofs->ignore()])->toEqual([Glob::of('docs/**')])
        ->and($proofs->write())->toBe(Writing::Never)
        ->and($settings->triage()->budget())->toEqual(Seconds::of(5400))
        ->and($triage->timeouts())->toBe(TimeoutMode::Unjudged)
        ->and($triage->limit())->toEqual(Seconds::of(30))
        ->and($triage->retries())->toBe(0)
        ->and($triage->confirmSurvivors())->toBeFalse()
        ->and($settings->ignores()->maxDays())->toBe(90)
        ->and($settings->ignores()->native())->toBe(NativeMarkers::Allow)
        ->and([...$settings->badge()])->toBe(['green' => 95])
        ->and($settings->effective()->pest()->patch())->toBeTrue()
        ->and($settings->effective()->pest()->canary())->toEqual(Group::named('canary'))
        ->and($settings->staticCheck()->tool())->toEqual(Choice::of('phpstan', Configs::options('{}')))
        ->and($settings->staticCheck()->config())->toEqual(Path::of('phpstan.dist.neon'))
        ->and($settings->staticCheck()->seconds())->toEqual(Seconds::of(45))
        ->and($settings->local()->watchBudget())->toEqual(Seconds::of(120))
        ->and($settings->local()->prePushBudget())->toEqual(Seconds::of(90));
});

it('reads each ignore as a mutant by its id or a mutator in a glob', function (): void {
    $entries = [...Configs::settings(EVERYTHING)->ignores()->entries()];
    $mutant = $entries[0];
    $pattern = $entries[1];

    expect($mutant)->toBeInstanceOf(IgnoredMutant::class)
        ->and($mutant instanceof IgnoredMutant
            ? [$mutant->mutant(), $mutant->reason(), $mutant->expires()]
            : [])->toEqual([
                MutantId::parse('3f9a1c2b7d04'),
                'Both branches build the same list',
                Day::of('2026-12-29'),
            ])
        ->and($pattern)->toBeInstanceOf(IgnoredPattern::class)
        ->and($pattern instanceof IgnoredPattern
            ? [$pattern->path(), $pattern->mutator(), $pattern->reason(), $pattern->expires()]
            : [])->toEqual([Glob::of('src/Log/**'), 'MethodCallRemoval', 'Logging is asserted elsewhere', Day::of('2026-10-01')])
        ->and(count($entries))->toBe(2);
});

it('reads each report with its reporter and where it is written', function (): void {
    $reports = [...Configs::settings(EVERYTHING)->reports()];

    expect(array_map(static fn(Report $report): array => [$report->reporter(), $report->path()], $reports))->toEqual([
        [Choice::of('sarif', Configs::options('{}')), Path::of('build/mutation.sarif')],
        [Choice::of('Acme\\GateSlack\\SlackReporter', Configs::options('{"channel":"#ci"}')), Absent::setting()],
    ]);
});

it('shows the memory cap beside the runner, and what it withholds only where it withholds anything', function (): void {
    $withholding = Configs::settings(['runner' => ['use' => 'pest', 'withhold' => ['DEPLOY_*']]]);

    expect(Configs::shown($withholding, 'runner'))->toBe(['use' => 'pest', 'withhold' => ['DEPLOY_*'], 'memory' => '1G'])
        ->and(Configs::shown(Configs::settings(['runner' => ['use' => 'pest']]), 'runner'))
        ->toBe(['use' => 'pest', 'memory' => '1G'])
        ->and(Configs::shown(Configs::settings(['runner' => ['use' => 'pest', 'memory' => '-1']]), 'runner'))
        ->toBe(['use' => 'pest', 'memory' => '-1'])
        ->and(Configs::shown(Configs::settings(EVERYTHING), 'runner'))
        ->toBe(['use' => 'infection', 'withhold' => ['DEPLOY_*', 'COMPOSER_AUTH'], 'memory' => '512M', 'workers' => 'fresh']);
});

it('refuses a runner that withholds without naming the runner, or withholds anything but names', function (
    array $runner,
    string $problem,
): void {
    expect(Configs::problems(Configs::validated(['runner' => $runner])))->toBe([$problem]);
})->with([
    'no runner at all' => [
        ['withhold' => ['DEPLOY_*']],
        'runner: expected a name, a class, or an object with use and with, got nothing',
    ],
    'options for no runner' => [['with' => ['a' => 1]], 'runner.use: expected a name or a class, got nothing'],
    'one name, not a list' => [
        ['use' => 'pest', 'withhold' => 'DEPLOY_*'],
        'runner.withhold: expected a list, got "DEPLOY_*"',
    ],
    'an empty name' => [
        ['use' => 'pest', 'withhold' => ['']],
        'runner.withhold[0]: expected a variable name or a glob, got ""',
    ],
]);

it('leaves an ignore without an end date open when nothing limits it', function (): void {
    $settings = Configs::settings([
        'runner' => 'pest',
        'ignores' => ['entries' => [['mutant' => '3f9a1c2b7d04', 'reason' => 'Equivalent']]],
    ]);
    $entries = [...$settings->ignores()->entries()];

    expect($entries[0]->expires())->toEqual(Absent::setting());
});

it('serialises the settings that affect results canonically, and only those', function (): void {
    expect(Configs::settings(['runner' => 'pest'])->canonical())->toBe(
        '{"ci":{"azure":{"definition":"azure-pipelines.yml"},"bitbucket":{"definition":"bitbucket-pipelines.yml"},'
        . '"buildkite":{"definition":".buildkite/pipeline.yml"},'
        . '"gitlab":{"template":".gitlab/mutation-gate.yml"},"jenkins":{"definition":"Jenkinsfile"}},'
        . '"flaky":{"confirmSurvivors":true},"mutators":{"except":[],"sets":[]},"packages":[],'
        . '"pest":{"canary":"mutation-canary","patch":false},'
        . '"runner":{"memory":"1G","use":"pest"},"staticCheck":{"seconds":60,"tool":"auto"},"tests":{"order":"killers-first"},'
        . '"timeouts":{"retries":20,"seconds":10},"treeSource":{"use":"phpunit","with":{"fallback":[]}}}',
    )->and(Configs::settings(EVERYTHING)->canonical())->toBe(
        '{"ci":{"azure":{"definition":"ci/azure.yml"},"bitbucket":{"definition":"ci/bitbucket.yml"},'
        . '"buildkite":{"definition":".buildkite/mutation.yml"},'
        . '"gitlab":{"template":".gitlab/gate.yml"},"jenkins":{"definition":"ci/Jenkinsfile"}},'
        . '"flaky":{"confirmSurvivors":false},'
        . '"mutators":{"except":["acme/RemoveAudit"],"sets":["acme","acme-auth"]},"packages":["packages/*"],'
        . '"pest":{"canary":"canary","patch":true},'
        . '"runner":{"memory":"512M","use":"infection","workers":"fresh"},'
        . '"staticCheck":{"config":"phpstan.dist.neon","seconds":45,"tool":"phpstan"},'
        . '"tests":{"order":"killers-first"},"timeouts":{"retries":0,"seconds":30},'
        . '"treeSource":{"use":"phpunit","with":{"fallback":["app","lib"]}},'
        . '"trees":[{"path":"app/Domain"},{"path":"app/Http"},'
        . '{"path":"app/Generated"},{"path":"app/Legacy"}]}',
    );
});

it('keys the memory cap in force, however it is written, and the default as the same cap written out', function (): void {
    $canonical = static fn(array $runner): string => Configs::settings(['runner' => $runner])->canonical();
    $default = $canonical(['use' => 'pest']);

    expect($canonical(['use' => 'pest', 'memory' => '1G']))->toBe($default)
        ->and($canonical(['use' => 'pest', 'memory' => '1024M']))->toBe($default)
        ->and($canonical(['use' => 'pest', 'memory' => '1073741824']))->toBe($default)
        ->and($canonical(['use' => 'pest', 'memory' => '2G']))->not->toBe($default);
});

it('keeps the settings that only judge or report out of the canonical form', function (): void {
    $judging = [
        ...EVERYTHING,
        'trees' => [
            ['path' => 'app/Domain', 'floor' => 50],
            ['path' => 'app/Http'],
            ['path' => 'app/Generated'],
            ['path' => 'app/Legacy'],
        ],
        'newCode' => ['floor' => 10],
        'reports' => [['use' => 'json', 'path' => 'build/mutation.json']],
        'ignores' => ['entries' => []],
        'budget' => '5m',
        'proofs' => ['write' => 'auto'],
        'coverage' => ['incremental' => true],
        'runner' => ['use' => 'infection', 'with' => [], 'memory' => '512M', 'workers' => 'fresh'],
    ];

    expect(Configs::settings($judging)->canonical())->toBe(Configs::settings(EVERYTHING)->canonical());
});

it('changes the canonical form with every setting that affects results', function (array $change): void {
    expect(Configs::settings([...EVERYTHING, ...$change])->canonical())
        ->not->toBe(Configs::settings(EVERYTHING)->canonical());
})->with([
    'the runner' => [['runner' => 'pest']],
    'the memory cap' => [['runner' => [...EVERYTHING['runner'], 'memory' => '-1']]],
    'how its workers start' => [['runner' => [...EVERYTHING['runner'], 'workers' => 'fork']]],
    'its tree source' => [['treeSource' => 'composer']],
    'a tree\'s path' => [['trees' => [['path' => 'app']]]],
    'the packages' => [['packages' => []]],
    'the timeout' => [['timeouts' => ['seconds' => 31]]],
    'the retries' => [['timeouts' => ['retries' => 1]]],
    'survivor confirmation' => [['flaky' => ['confirmSurvivors' => true]]],
    'the Pest patches' => [['pest' => ['patch' => false]]],
    'the canary group' => [['pest' => ['canary' => 'other']]],
    'the static analyser' => [['staticCheck' => ['tool' => 'psalm', 'config' => 'phpstan.dist.neon']]],
    'the static analyser\'s config' => [['staticCheck' => ['tool' => 'phpstan', 'config' => 'phpstan.neon', 'seconds' => 45]]],
    'the static analyser\'s limit' => [['staticCheck' => ['tool' => 'phpstan', 'config' => 'phpstan.dist.neon', 'seconds' => 46]]],
    'a tree\'s exclude' => [['trees' => [...EVERYTHING['trees'], ['path' => 'lib', 'exclude' => ['lib/Gen/**']]]]],
    'the test order' => [['tests' => ['order' => 'runner']]],
    'the tree source\'s fallback' => [['treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]]]],
    'the Bitbucket pipeline' => [['ci' => [...EVERYTHING['ci'], 'bitbucket' => ['definition' => 'ci/other.yml']]]],
    'the Jenkinsfile' => [['ci' => [...EVERYTHING['ci'], 'jenkins' => ['definition' => 'ci/Other.Jenkinsfile']]]],
    'the mutator sets' => [['mutators' => [...EVERYTHING['mutators'], 'sets' => ['acme']]]],
    'the mutators turned off' => [['mutators' => [...EVERYTHING['mutators'], 'except' => []]]],
]);

it('changes the canonical form with the options of a runner or a tree source', function (string $setting): void {
    $chosen = static fn(int $workers): string => Configs::settings([
        'runner' => 'pest',
        $setting => ['use' => 'Acme\\Gate\\Chosen', 'with' => ['workers' => $workers]],
    ])->canonical();

    expect($chosen(4))->not->toBe($chosen(8));
})->with(['runner', 'treeSource']);

it('keys a tree with no exclude as one with an empty exclude', function (): void {
    $tree = static fn(array $tree): string => Configs::settings(['runner' => 'pest', 'trees' => [$tree]])->canonical();

    expect($tree(['path' => 'src', 'exclude' => []]))->toBe($tree(['path' => 'src']));
});

it('reports every problem at once, each at its path with what was expected', function (): void {
    expect(Configs::problems(Configs::validated([
        'runner' => 3,
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => 'app', 'extra' => 1]],
        'trees' => [['path' => 'app', 'floor' => '80'], ['floor' => 0]],
        'newcode' => ['floor' => 100],
        'uncovered' => 'all',
        'holds' => ['hotPath' => 1.5],
        'shards' => ['seconds' => 0, 'max' => 2.5],
        'costs' => ['secondsPerLine' => ['src' => -1]],
        'proofs' => ['store' => 's3'],
        'budget' => '15 minutes',
        'ignores' => ['entries' => [
            ['mutant' => 123456789012, 'reason' => 'Equivalent'],
            ['mutant' => '3f9a1c2b7d04', 'path' => 'src/**', 'reason' => 'Equivalent'],
            ['path' => 'src', 'reason' => 'Equivalent', 'expires' => '2027-02-30'],
        ]],
        'reports' => [['use' => 'sarif'], ['path' => 'build/report']],
        'pest' => ['patch' => 'yes'],
        'staticCheck' => ['tool' => ['use' => 'phpstan', 'with' => ['level' => 9]], 'config' => '', 'seconds' => 0],
    ])))->toBe([
        'runner: expected a name, a class, or an object with use and with, got 3',
        'treeSource.with.fallback: expected a list, got "app"',
        'treeSource.with.extra: unknown key',
        'trees[0].floor: expected a number from 0 to 100, got "80"',
        'trees[1].path: expected a path, got nothing',
        'uncovered: expected "count" or "exclude", got "all"',
        'holds.hotPath: expected a number from 0 to 1, got 1.5',
        'shards.seconds: expected an integer of at least 1, got 0',
        'shards.max: expected an integer of at least 1, got 2.5',
        'costs.secondsPerLine["src"]: expected a number of at least 0, got -1',
        'proofs.store.with.bucket: expected a bucket name, got nothing',
        'budget: expected a duration such as 90s, 15m or 1h30m, got "15 minutes"',
        'ignores.entries[0].mutant: expected a mutant id, twelve lowercase hex characters in quotes, got 123456789012',
        'ignores.entries[1]: expected either mutant, or path and mutator, but not both',
        'ignores.entries[2].expires: expected a date written YYYY-MM-DD, got "2027-02-30"',
        'reports[0].path: expected a path, got nothing',
        'reports[1].use: expected a name or a class, got nothing',
        'pest.patch: expected true or false, got "yes"',
        'staticCheck.tool.with.level: unknown key',
        'staticCheck.config: expected a path, got ""',
        'staticCheck.seconds: expected an integer of at least 1, got 0',
        'newcode: unknown key, did you mean newCode?',
    ]);
});

it('refuses a config without a runner', function (): void {
    expect(Configs::problems(Configs::validated([])))
        ->toBe(['runner: expected a name, a class, or an object with use and with, got nothing']);
});

it('refuses a config that is not an object', function (string $json, string $problem): void {
    expect(Configs::problems(Configs::validated($json)))->toBe([$problem]);
})->with([
    'a list' => ['[{"runner": "pest"}]', ': expected an object, got a list'],
    'a number' => ['3', ': expected an object, got 3'],
]);

it('refuses a string where a number belongs, and a fraction where an integer does', function (): void {
    expect(Configs::problems(Configs::validated(
        '{"runner": "pest", "newCode": {"floor": "100"}, "timeouts": {"seconds": 10.0, "retries": -1}, '
        . '"flaky": {"confirmSurvivors": 1}, "staticCheck": {"seconds": 2.5}}',
    )))->toBe([
        'newCode.floor: expected a number from 0 to 100, got "100"',
        'timeouts.seconds: expected an integer of at least 1, got 10.0',
        'timeouts.retries: expected an integer of at least 0, got -1',
        'flaky.confirmSurvivors: expected true or false, got 1',
        'staticCheck.seconds: expected an integer of at least 1, got 2.5',
    ]);
});

it('takes the ends of every range', function (): void {
    $settings = Configs::settings([
        'runner' => 'pest',
        'trees' => [['path' => 'src', 'floor' => 0, 'reason' => 'Generated']],
        'newCode' => ['floor' => 0],
        'holds' => ['hotPath' => 0],
        'costs' => ['secondsPerLine' => ['' => 0]],
        'badge' => ['colors' => ['green' => 100, 'red' => 0]],
        'timeouts' => ['seconds' => 1, 'retries' => 0],
        'shards' => ['seconds' => 1, 'max' => 1],
        'ignores' => ['maxDays' => 1],
    ]);

    expect($settings->floors()->newCode())->toEqual(Floor::of(0))
        ->and($settings->reach()->hotPaths()->share())->toBe(0.0)
        ->and([...$settings->badge()])->toBe(['green' => 100, 'red' => 0])
        ->and($settings->ignores()->maxDays())->toBe(1);
});

it('refuses a path or a glob that goes up out of the project, or is absolute', function (): void {
    expect(Configs::problems(Configs::validated([
        'runner' => 'pest',
        'trees' => [['path' => '../../etc'], ['path' => 'src', 'exclude' => ['../gen/**', '/abs/src/Gen/**']], ['path' => '/abs/src']],
        'packages' => ['/abs/*'],
        'costs' => ['secondsPerLine' => ['' => 1, '../x' => 1, '/abs' => 1]],
        'ci' => ['gitlab' => ['template' => '/abs.yml']],
        'baseline' => ['path' => '../x.json'],
        'proofs' => ['store' => ['use' => 'directory', 'with' => ['path' => '/var/ledger']], 'ignore' => ['/etc/**']],
        'reports' => [['use' => 'json', 'path' => '/tmp/x.json']],
    ])))->toBe([
        'trees[0].path: expected a path inside the project, got "../../etc"',
        'trees[1].exclude[0]: expected a path inside the project, got "../gen/**"',
        'trees[1].exclude[1]: expected a path inside the project, got "/abs/src/Gen/**"',
        'trees[2].path: expected a path inside the project, got "/abs/src"',
        'baseline.path: expected a path inside the project, got "../x.json"',
        'packages[0]: expected a path inside the project, got "/abs/*"',
        'costs.secondsPerLine["../x"]: expected a path inside the project, got "../x"',
        'costs.secondsPerLine["/abs"]: expected a path inside the project, got "/abs"',
        'ci.gitlab.template: expected a path inside the project, got "/abs.yml"',
        'proofs.store.with.path: expected a path inside the project, got "/var/ledger"',
        'proofs.ignore[0]: expected a path inside the project, got "/etc/**"',
        'reports[0].path: expected a path inside the project, got "/tmp/x.json"',
    ]);
});

it('keeps an absolute path the command line names, and no path that goes up', function (): void {
    $line = Definition::layer(
        Node::config('{"reports": [{"use": "json", "path": "/tmp/x.json"}]}'),
        ProjectRoot::commandLine(),
    );
    $up = Definition::layer(
        Node::config('{"reports": [{"use": "json", "path": "../x.json"}]}'),
        ProjectRoot::commandLine(),
    );

    expect($line instanceof Layer ? Configs::decoded($line) : Configs::problems($line))
        ->toBe(['reports' => [['use' => 'json', 'path' => '/tmp/x.json']]])
        ->and(Configs::problems($up))->toBe(['reports[0].path: expected a path inside the project, got "../x.json"']);
});

it('keeps each path of the tree source\'s fallback once, as the effective config writes it', function (): void {
    $settings = Configs::settings([
        'runner' => 'pest',
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['./app/', 'app', 'lib']]],
    ]);

    expect($settings->treeSource()->options()->written()->line())->toBe('{"fallback":["app","lib"]}')
        ->and(Configs::settings(Configs::effective($settings))->treeSource())
        ->toEqual($settings->treeSource());
});

it('refuses a floor of 0 without the reason it needs', function (): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'trees' => [['path' => 'src', 'floor' => 0]]])))
        ->toBe(['trees[0].reason: expected a reason when floor is 0, got nothing']);
});

it('refuses a reason beside a floor above 0, or beside none, which it would do nothing for', function (): void {
    expect(Configs::problems(Configs::validated([
        'runner' => 'pest',
        'trees' => [['path' => 'src', 'floor' => 0.01, 'reason' => 'Why'], ['path' => 'lib', 'reason' => 'Why']],
    ])))->toBe([
        'trees[0].reason: expected no reason, as only a floor of 0 takes one, got "Why"',
        'trees[1].reason: expected no reason, as only a floor of 0 takes one, got "Why"',
    ]);
});

it('reads an empty list of trees as no tree at all, not as the tree source\'s', function (): void {
    $trees = Configs::settings(['runner' => 'pest', 'trees' => []])->floors()->trees();

    expect($trees instanceof Absent ? 'absent' : count($trees))->toBe(0);
});

it('refuses an ignore that is neither a mutant nor a mutator in a glob', function (array $entry): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'ignores' => ['entries' => [$entry]]])))
        ->toBe(['ignores.entries[0]: expected either mutant, or path and mutator, but not both']);
})->with([
    'a reason alone' => [['reason' => 'Equivalent']],
    'a path without a mutator' => [['path' => 'src/**', 'reason' => 'Equivalent']],
    'a mutator without a path' => [['mutator' => 'Plus', 'reason' => 'Equivalent']],
    'a mutant and a mutator' => [['mutant' => '3f9a1c2b7d04', 'mutator' => 'Plus', 'reason' => 'Equivalent']],
    'all three' => [['mutant' => '3f9a1c2b7d04', 'path' => 'src/**', 'mutator' => 'Plus', 'reason' => 'Equivalent']],
]);

it('refuses an ignore that does not expire within ignores.maxDays of today', function (): void {
    expect(Configs::problems(Configs::validated([
        'runner' => 'pest',
        'ignores' => [
            'maxDays' => 30,
            'entries' => [
                ['mutant' => '3f9a1c2b7d04', 'reason' => 'On the day', 'expires' => '2026-10-30'],
                ['mutant' => '81d0c9e2aa17', 'reason' => 'A day late', 'expires' => '2026-10-31'],
                ['path' => 'src/**', 'mutator' => 'Plus', 'reason' => 'Never'],
            ],
        ],
    ])))->toBe([
        'ignores.entries[1].expires: expected a date by 2026-10-30, within ignores.maxDays of today, got "2026-10-31"',
        'ignores.entries[2].expires: expected a date by 2026-10-30, within ignores.maxDays of today, got nothing',
    ]);
});

it('reports what is wrong in a layer before what only every layer together can say', function (): void {
    expect(Configs::problems(Configs::validated([
        'budget' => 'soon',
        'ignores' => [
            'maxDays' => 30,
            'entries' => [['mutant' => '81d0c9e2aa17', 'reason' => 'A day late', 'expires' => '2026-10-31']],
        ],
    ])))->toBe(['budget: expected a duration such as 90s, 15m or 1h30m, got "soon"']);
});

it('judges expiries once every ignore is written as it must be', function (): void {
    expect(Configs::problems(Configs::validated([
        'runner' => 'pest',
        'ignores' => [
            'maxDays' => 30,
            'entries' => [
                ['mutant' => '81d0c9e2aa17', 'reason' => 'A day late', 'expires' => '2026-10-31'],
                ['mutant' => '3f9a1c2b7d04', 'reason' => 'Not a date', 'expires' => 'soon'],
            ],
        ],
    ])))->toBe(['ignores.entries[1].expires: expected a date written YYYY-MM-DD, got "soon"']);
});

it('judges no expiry where a config is described rather than read', function (): void {
    $described = Configs::layer(
        ['runner' => 'pest', 'ignores' => ['maxDays' => 1, 'entries' => [['mutant' => '81d0c9e2aa17', 'reason' => 'x']]]],
    );

    expect(Configs::problems($described))->toBe([]);
});

it('judges no expiry against a maxDays written wrong, or entries that are not a list', function (array $ignores): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'ignores' => $ignores])))
        ->each->not->toStartWith('ignores.entries[0].expires: expected a date by');
})->with([
    'maxDays as text' => [['maxDays' => '30', 'entries' => [['mutant' => '81d0c9e2aa17', 'reason' => 'Never']]]],
    'maxDays of 0' => [['maxDays' => 0, 'entries' => [['mutant' => '81d0c9e2aa17', 'reason' => 'Never']]]],
    'entries as an object' => [
        ['maxDays' => 30, 'entries' => ['a' => ['mutant' => '81d0c9e2aa17', 'reason' => 'Never']]],
    ],
]);

it('judges expiries against a maxDays of one day', function (): void {
    expect(Configs::problems(Configs::validated([
        'runner' => 'pest',
        'ignores' => ['maxDays' => 1, 'entries' => [['mutant' => '81d0c9e2aa17', 'reason' => 'Never']]],
    ])))->toBe([
        'ignores.entries[0].expires: expected a date by 2026-10-01, within ignores.maxDays of today, got nothing',
    ]);
});

it('takes any $schema a file names, and leaves it out of the effective config', function (mixed $schema): void {
    expect(Configs::decoded(Configs::settings(['$schema' => $schema, 'runner' => 'pest'])->effective()))
        ->not->toHaveKey('$schema');
})->with([
    'a path' => ['vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json'],
    'an empty string' => [''],
    'a number' => [3],
]);

it('applies no preset when a config names none', function (): void {
    expect([...Configs::settings(['runner' => 'pest'])->presets()])->toBe([]);
});

it('reads an adapter written as an object, checking a built-in one\'s options strictly', function (): void {
    expect(Configs::problems(Configs::validated(['runner' => ['use' => 'pest', 'With' => []]])))
        ->toBe(['runner.With: unknown key, did you mean with?'])
        ->and(Configs::problems(Configs::validated(['runner' => ['use' => 'pest', 'with' => ['workers' => 4]]])))
        ->toBe(['runner.with.workers: unknown key'])
        ->and(Configs::problems(Configs::validated(['runner' => ['with' => []]])))
        ->toBe(['runner.use: expected a name or a class, got nothing'])
        ->and(Configs::problems(Configs::validated(['runner' => ''])))
        ->toBe(['runner: expected a name, a class, or an object with use and with, got ""']);
});

it('refuses an adapter written as neither a name nor an object', function (string $key, array $config): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', ...$config])))
        ->toBe([sprintf('%s: expected a name, a class, or an object with use and with, got 5', $key)]);
})->with([
    'the tree source' => ['treeSource', ['treeSource' => 5]],
    'the CI plan' => ['ci.plan', ['ci' => ['plan' => 5]]],
    'the proof store' => ['proofs.store', ['proofs' => ['store' => 5]]],
    'the static analyser' => ['staticCheck.tool', ['staticCheck' => ['tool' => 5]]],
]);

it('leaves the options of a class or another extension\'s adapter to it', function (): void {
    $settings = Configs::settings([
        'runner' => ['use' => 'Acme\\Gate\\Runner', 'with' => ['workers' => 4]],
        'proofs' => ['store' => 'Acme\\Gate\\Store'],
        'staticCheck' => ['tool' => ['use' => 'Acme\\Gate\\Analyser', 'with' => ['level' => 9]]],
    ]);

    expect($settings->runner()->choice())->toEqual(Choice::of('Acme\\Gate\\Runner', Configs::options('{"workers":4}')))
        ->and($settings->proofs()->store())->toEqual(Choice::of('Acme\\Gate\\Store', Configs::options('{}')))
        ->and($settings->staticCheck()->tool())
        ->toEqual(Choice::of('Acme\\Gate\\Analyser', Configs::options('{"level":9}')))
        ->and(Configs::shown($settings, 'runner'))
        ->toBe(['use' => 'Acme\\Gate\\Runner', 'with' => ['workers' => 4], 'memory' => '1G']);
});

it('says whether the store keeps its ledgers on this machine', function (string|array $store, bool $kept): void {
    expect(Configs::settings(['runner' => 'pest', 'proofs' => ['store' => $store]])->proofs()->keptOnDisk())->toBe($kept);
})->with([
    'the directory' => ['directory', true],
    'the directory at a path' => [['use' => 'directory', 'with' => ['path' => 'cache']], true],
    'a bucket' => [['use' => 's3', 'with' => ['bucket' => 'ledgers']], false],
    'a class' => ['Acme\\Gate\\Store', false],
]);

it('reads a path as the repository spells it', function (): void {
    $settings = Configs::settings(['runner' => 'pest', 'baseline' => ['path' => './build//baseline.json']]);

    expect($settings->floors()->baseline())->toEqual(Path::of('build/baseline.json'))
        ->and(Configs::shown($settings, 'baseline', 'path'))->toBe('build/baseline.json');
});

it('takes one preset by its name, or a list of them', function (): void {
    expect([...Configs::settings(['runner' => 'pest', 'preset' => 'laravel'])->presets()])->toBe(['laravel'])
        ->and(Configs::problems(Configs::validated(['runner' => 'pest', 'preset' => ['laravel', '']])))
        ->toBe(['preset: expected a preset name, or a list of them, got a list'])
        ->and(Configs::problems(Configs::validated(['runner' => 'pest', 'preset' => ['a' => 'laravel']])))
        ->toBe(['preset: expected a preset name, or a list of them, got an object']);
});

it('accepts the $schema key and ignores what it says', function (): void {
    expect(Configs::settings(['$schema' => 'anything', 'runner' => 'pest'])->canonical())
        ->toBe(Configs::settings(['runner' => 'pest'])->canonical());
});

it('refuses a map whose keys are settings where a map of numbers belongs', function (): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'costs' => ['secondsPerLine' => [0.2]]])))
        ->toBe(['costs.secondsPerLine: expected an object of numbers, got a list'])
        ->and(Configs::problems(Configs::validated(['runner' => 'pest', 'ci' => ['buildkite' => ['step' => [1]]]])))
        ->toBe(['ci.buildkite.step: expected an object, got a list'])
        ->and(Configs::problems(Configs::validated(['runner' => 'pest', 'shards' => [600]])))
        ->toBe(['shards: expected an object, got a list']);
});

it('refuses a badge written outside the project, by its entry or its options', function (
    array $entry,
    string $problem,
): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'reports' => [['use' => 'badge', ...$entry]]])))
        ->toBe([$problem]);
})->with([
    'an absolute path' => [['path' => '/tmp/x'], 'reports[0].path: expected a path inside the project, got "/tmp/x"'],
    'a path up out of the project' => [['path' => '../x'], 'reports[0].path: expected a path inside the project, got "../x"'],
    'a path among its options' => [['with' => ['path' => '/tmp/x']], 'reports[0].with.path: unknown key'],
]);

it('takes a path for a report it writes, may for the badge, and takes none for one it prints or sends', function (
    string $use,
    array $entry,
    array $problems,
): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'reports' => [['use' => $use, ...$entry]]])))
        ->toBe($problems);
})->with([
    'a file with none' => ['json', [], ['reports[0].path: expected a path, got nothing']],
    'the badge with none' => ['badge', [], []],
    'the badge with one' => ['badge', ['path' => 'publish'], []],
    'the console with one' => [
        'console',
        ['path' => 'out.txt'],
        ['reports[0].path: expected nothing, as console writes no file, got "out.txt"'],
    ],
    'a comment with one' => [
        'github-comment',
        ['path' => 'out.md'],
        ['reports[0].path: expected nothing, as github-comment writes no file, got "out.md"'],
    ],
]);

it('reads the problems report\'s only and the comment\'s identity, and refuses what they do not take', function (): void {
    $problems = static fn(array $with): array => Configs::problems(Configs::validated([
        'runner' => 'pest',
        'reports' => [['use' => 'problems', 'with' => $with]],
    ]));

    expect($problems(['only' => 'changed']))->toBe([])
        ->and($problems(['only' => 'some']))->toBe(['reports[0].with.only: expected "all" or "changed", got "some"'])
        ->and(Configs::problems(Configs::validated([
            'runner' => 'pest',
            'reports' => [['use' => 'github-comment', 'with' => ['identity' => 7]]],
        ])))->toBe(['reports[0].with.identity: expected an account name, got 7']);
});

it('refuses a Buildkite step whose command is not text, or a list of text, at the command', function (): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', 'ci' => ['buildkite' => ['step' => ['command' => 7]]]])))
        ->toBe(['ci.buildkite.step.command: expected a command, or a list of commands, as text, got 7']);
});

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
    expect(Configs::valid(EVERYTHING)->php(ProjectRoot::origin())->code())
        ->toContain("Pipeline::azureDefinition('ci/azure.yml')")
        ->toContain("Pipeline::bitbucketDefinition('ci/bitbucket.yml')")
        ->toContain("Pipeline::jenkinsDefinition('ci/Jenkinsfile')")
        ->toContain('StaticCheck::seconds(45)')
        ->toContain('Survivors::firstAtMost(5)');
});

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

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Canonical;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\IgnoredMutant;
use NightWorksIO\MutationGate\Core\Config\IgnoredPattern;
use NightWorksIO\MutationGate\Core\Config\Improvement;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\Config\Report;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
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
use NightWorksIO\MutationGate\Tests\Support\SettingsCases;

describe('Settings', function (): void {
    it('fills every setting a config leaves out with its default', function (): void {
        expect(Configs::effective(Configs::settings(['runner' => 'pest'])))->toBe(SettingsCases::DEFAULTS);
    });

    it('reads the effective config back into the same settings', function (array|string $config): void {
        $settings = Configs::settings($config);

        expect(Configs::settings(Configs::effective($settings)))->toEqual($settings);
    })->with([
        'every setting' => [SettingsCases::EVERYTHING],
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
        $settings = Configs::settings(SettingsCases::EVERYTHING);
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
        $entries = [...Configs::settings(SettingsCases::EVERYTHING)->ignores()->entries()];
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
        $reports = [...Configs::settings(SettingsCases::EVERYTHING)->reports()];

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
            ->and(Configs::shown(Configs::settings(SettingsCases::EVERYTHING), 'runner'))
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
            . '"buildkite":{"definition":".buildkite/pipeline.yml","step":{}},'
            . '"gitlab":{"template":".gitlab/mutation-gate.yml"},"jenkins":{"definition":"Jenkinsfile"}},'
            . '"extensions":[],"flaky":{"confirmSurvivors":true},"mutators":{"except":[],"sets":[]},"packages":[],'
            . '"pest":{"canary":"mutation-canary","patch":false},"pruning":{"enabled":true,"window":500},'
            . '"runner":{"memory":"1G","use":"pest"},"staticCheck":{"seconds":60,"tool":"auto"},"tests":{"order":"killers-first"},'
            . '"timeouts":{"most":300,"seconds":10,"tighter":{"floor":7,"mutators":["RemoveArrayItem","DecrementInteger","IncrementInteger","ForeachEmptyIterable","UnwrapArrayValues","InstanceOfToTrue","InstanceOfToFalse","TernaryNegated","ArrayItemRemoval","Foreach_","InstanceOf_","Ternary"]}},"treeSource":{"use":"phpunit","with":{"fallback":[]}}}',
        )->and(Configs::settings(SettingsCases::EVERYTHING)->canonical())->toBe(
            '{"ci":{"azure":{"definition":"ci/azure.yml"},"bitbucket":{"definition":"ci/bitbucket.yml"},'
            . '"buildkite":{"definition":".buildkite/mutation.yml","step":{"agents":{"queue":"mutation"}}},'
            . '"gitlab":{"template":".gitlab/gate.yml"},"jenkins":{"definition":"ci/Jenkinsfile"}},'
            . '"extensions":["Acme\\\\GateSlack\\\\SlackExtension"],"flaky":{"confirmSurvivors":false},'
            . '"mutators":{"except":["acme/RemoveAudit"],"sets":["acme","acme-auth"]},"packages":["packages/*"],'
            . '"pest":{"canary":"canary","patch":true},"pruning":{"enabled":false,"window":200},'
            . '"runner":{"memory":"512M","use":"infection","withhold":["DEPLOY_*","COMPOSER_AUTH"],"workers":"fresh"},'
            . '"staticCheck":{"config":"phpstan.dist.neon","seconds":45,"tool":"phpstan"},'
            . '"tests":{"order":"killers-first","suites":["Unit","Plugins"]},"timeouts":{"most":120,"seconds":30,"tighter":{"floor":7,"mutators":["RemoveArrayItem","DecrementInteger","IncrementInteger","ForeachEmptyIterable","UnwrapArrayValues","InstanceOfToTrue","InstanceOfToFalse","TernaryNegated","ArrayItemRemoval","Foreach_","InstanceOf_","Ternary"]}},'
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
            ...SettingsCases::EVERYTHING,
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
            'reach' => ['everything' => ['bootstrap/**']],
            'ci' => [...SettingsCases::EVERYTHING['ci'], 'check' => 'other', 'trustMergedPullRequests' => false],
        ];

        expect(Configs::settings($judging)->canonical())->toBe(Configs::settings(SettingsCases::EVERYTHING)->canonical());
    });

    it('keeps out of what decides how the gate runs only the settings that judge or report', function (): void {
        $judging = [
            ...SettingsCases::EVERYTHING,
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
        ];

        expect(Canonical::decidingIn(Configs::settings($judging)))->toBe(Canonical::decidingIn(Configs::settings(SettingsCases::EVERYTHING)));
    });

    it('changes what decides how the gate runs, and not the canonical form, with every setting that decides how it runs', function (array $change): void {
        expect(Canonical::decidingIn(Configs::settings([...SettingsCases::EVERYTHING, ...$change])))
            ->not->toBe(Canonical::decidingIn(Configs::settings(SettingsCases::EVERYTHING)))
            ->and(Configs::settings([...SettingsCases::EVERYTHING, ...$change])->canonical())
            ->toBe(Configs::settings(SettingsCases::EVERYTHING)->canonical());
    })->with([
        'what reaches everything' => [['reach' => ['everything' => ['bootstrap/**']]]],
        'the check' => [['ci' => [...SettingsCases::EVERYTHING['ci'], 'check' => 'other']]],
        'the default branch' => [['ci' => [...SettingsCases::EVERYTHING['ci'], 'defaultBranch' => 'develop']]],
        'trusting merged pull requests' => [['ci' => [...SettingsCases::EVERYTHING['ci'], 'trustMergedPullRequests' => false]]],
        'the proof store' => [['proofs' => [...SettingsCases::EVERYTHING['proofs'], 'store' => ['use' => 's3', 'with' => ['bucket' => 'forged']]]]],
        'the paths proofs ignore' => [['proofs' => [...SettingsCases::EVERYTHING['proofs'], 'ignore' => ['build/**']]]],
        'kept coverage' => [['coverage' => ['incremental' => true]]],
    ]);

    it('changes the canonical form with every setting that affects results', function (array $change): void {
        expect(Configs::settings([...SettingsCases::EVERYTHING, ...$change])->canonical())
            ->not->toBe(Configs::settings(SettingsCases::EVERYTHING)->canonical());
    })->with([
        'the runner' => [['runner' => 'pest']],
        'the memory cap' => [['runner' => [...SettingsCases::EVERYTHING['runner'], 'memory' => '-1']]],
        'how its workers start' => [['runner' => [...SettingsCases::EVERYTHING['runner'], 'workers' => 'fork']]],
        'its tree source' => [['treeSource' => 'composer']],
        'a tree\'s path' => [['trees' => [['path' => 'app']]]],
        'the packages' => [['packages' => []]],
        'the timeout' => [['timeouts' => ['seconds' => 31]]],
        'the most' => [['timeouts' => ['most' => 121]]],
        'survivor confirmation' => [['flaky' => ['confirmSurvivors' => true]]],
        'the Pest patches' => [['pest' => ['patch' => false]]],
        'the canary group' => [['pest' => ['canary' => 'other']]],
        'the static analyser' => [['staticCheck' => ['tool' => 'psalm', 'config' => 'phpstan.dist.neon']]],
        'the static analyser\'s config' => [['staticCheck' => ['tool' => 'phpstan', 'config' => 'phpstan.neon', 'seconds' => 45]]],
        'the static analyser\'s limit' => [['staticCheck' => ['tool' => 'phpstan', 'config' => 'phpstan.dist.neon', 'seconds' => 46]]],
        'a tree\'s exclude' => [['trees' => [...SettingsCases::EVERYTHING['trees'], ['path' => 'lib', 'exclude' => ['lib/Gen/**']]]]],
        'the test order' => [['tests' => ['order' => 'runner']]],
        'the tree source\'s fallback' => [['treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]]]],
        'the Bitbucket pipeline' => [['ci' => [...SettingsCases::EVERYTHING['ci'], 'bitbucket' => ['definition' => 'ci/other.yml']]]],
        'the Jenkinsfile' => [['ci' => [...SettingsCases::EVERYTHING['ci'], 'jenkins' => ['definition' => 'ci/Other.Jenkinsfile']]]],
        'the mutator sets' => [['mutators' => [...SettingsCases::EVERYTHING['mutators'], 'sets' => ['acme']]]],
        'the mutators turned off' => [['mutators' => [...SettingsCases::EVERYTHING['mutators'], 'except' => []]]],
        'the extensions' => [['extensions' => []]],
        'what the runner withholds' => [['runner' => [...SettingsCases::EVERYTHING['runner'], 'withhold' => []]]],
        'the Buildkite step' => [['ci' => [...SettingsCases::EVERYTHING['ci'], 'buildkite' => ['definition' => '.buildkite/mutation.yml']]]],
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
})->group('holds:src/Core/Config/Floors.php', 'holds:src/Core/Config/Shards.php');

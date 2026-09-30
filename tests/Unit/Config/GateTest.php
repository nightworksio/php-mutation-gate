<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Badge;
use NightWorksIO\MutationGate\Config\Baseline;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Ci;
use NightWorksIO\MutationGate\Config\Flaky;
use NightWorksIO\MutationGate\Config\Floor;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Config\Ignores;
use NightWorksIO\MutationGate\Config\Load;
use NightWorksIO\MutationGate\Config\Local;
use NightWorksIO\MutationGate\Config\Option;
use NightWorksIO\MutationGate\Config\Pest;
use NightWorksIO\MutationGate\Config\Preset;
use NightWorksIO\MutationGate\Config\Proofs;
use NightWorksIO\MutationGate\Config\Reach;
use NightWorksIO\MutationGate\Config\Report;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\Shards;
use NightWorksIO\MutationGate\Config\Source;
use NightWorksIO\MutationGate\Config\StaticCheck;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Config\Tree;
use NightWorksIO\MutationGate\Config\Uncovered;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('writes nothing for a config with no settings', function (): void {
    expect(Gate::configure()->written()->line())->toBe('{}');
});

it('writes the README\'s example as the README\'s JSON', function (): void {
    expect(Configs::written(
        Gate::configure()
            ->preset(Preset::laravel())
            ->runner(Runner::pest())
            ->trees(
                Tree::at('app/Domain', floor: 100),
                Tree::at('app/Http', floor: 80),
            )
            ->newCode(Floor::of(100))
            ->ignoring(
                Ignore::mutant('3f9a1c2b7d04', because: 'Both branches build the same list', until: '2027-03-31'),
            )
            ->reporting(Report::sarif('build/mutation.sarif'), Report::html('build/mutation')),
    ))->toBe([
        'preset' => 'laravel',
        'runner' => 'pest',
        'trees' => [['path' => 'app/Domain', 'floor' => 100], ['path' => 'app/Http', 'floor' => 80]],
        'newCode' => ['floor' => 100],
        'ignores' => [
            'entries' => [
                [
                    'mutant' => '3f9a1c2b7d04',
                    'reason' => 'Both branches build the same list',
                    'expires' => '2027-03-31',
                ],
            ],
        ],
        'reports' => [
            ['use' => 'sarif', 'path' => 'build/mutation.sarif'],
            ['use' => 'html', 'path' => 'build/mutation'],
        ],
    ]);
});

it('writes every setting of the configuration reference', function (): void {
    expect(Configs::written(
        Gate::configure()
            ->extensions(Load::extension('Acme\\A'), Load::extension('Acme\\B'))
            ->preset(Preset::library())
            ->runner(Runner::uses(
                'Acme\\Runner',
                Option::of('workers', 4),
                Option::list('groups', 'a', 'b'),
                Option::nested('retry', Option::of('times', 2), Option::of('on', value: true)),
            ))
            ->treeSource(Source::composer())
            ->trees(Tree::at('src'), Tree::at('app', floor: 83.5), Tree::at('gen', floor: 0, because: 'Generated'))
            ->newCode(Floor::of(90))
            ->ignoring(
                Ignore::mutant('3f9a1c2b7d04', because: 'Same'),
                Ignore::mutator('Plus', in: 'src/**', because: 'Why', until: '2027-01-01'),
            )
            ->ignoring(Ignore::mutant('81d0c9e2aa17', because: 'Also', until: '2027-02-01'))
            ->reporting(
                Report::json('a.json'),
                Report::junit('b.xml'),
                Report::sarif('c.sarif'),
                Report::html('d'),
                Report::uses('Acme\\Slack', Option::of('channel', '#ci')),
                Report::writing('acme', 'e.txt'),
            )
            ->with(
                Uncovered::excluded(),
                Baseline::at('b.json'),
                Baseline::reportingImprovement(),
                Reach::packages('packages/*'),
                Reach::everything('config/**'),
                Reach::hotPath(0.5),
                Shards::seconds(900),
                Shards::max(8),
                Shards::secondsPerLine('', 0.3),
                Shards::secondsPerLine('src', 1),
                Ci::gitlab(),
                Ci::defaultBranch('trunk'),
                Ci::gitlabTemplate('ci.yml'),
                Ci::buildkiteStep(Option::of('label', 'm')),
                Proofs::s3('bucket', region: 'auto', endpoint: 'https://r2'),
                Proofs::ignore('docs/**'),
                Proofs::readOnly(),
                Budget::of('15m'),
                Timeouts::unjudged(),
                Timeouts::seconds(30),
                Timeouts::retries(5),
                Flaky::notConfirmingSurvivors(),
                Ignores::within(30),
                Ignores::allowingNativeMarkers(),
                Badge::colour('green', 85),
                Pest::patched(),
                Pest::canary('canary'),
                StaticCheck::phpstan(),
                StaticCheck::config('phpstan.dist.neon'),
                Local::watchBudget('2m'),
                Local::prePushBudget('10m'),
            ),
    ))->toBe([
        'extensions' => ['Acme\\A', 'Acme\\B'],
        'preset' => 'library',
        'runner' => [
            'use' => 'Acme\\Runner',
            'with' => ['workers' => 4, 'groups' => ['a', 'b'], 'retry' => ['times' => 2, 'on' => true]],
        ],
        'treeSource' => 'composer',
        'trees' => [
            ['path' => 'src'],
            ['path' => 'app', 'floor' => 83.5],
            ['path' => 'gen', 'floor' => 0, 'reason' => 'Generated'],
        ],
        'newCode' => ['floor' => 90],
        'ignores' => [
            'entries' => [
                ['mutant' => '3f9a1c2b7d04', 'reason' => 'Same'],
                ['path' => 'src/**', 'mutator' => 'Plus', 'reason' => 'Why', 'expires' => '2027-01-01'],
                ['mutant' => '81d0c9e2aa17', 'reason' => 'Also', 'expires' => '2027-02-01'],
            ],
            'maxDays' => 30,
            'native' => 'allow',
        ],
        'reports' => [
            ['use' => 'json', 'path' => 'a.json'],
            ['use' => 'junit', 'path' => 'b.xml'],
            ['use' => 'sarif', 'path' => 'c.sarif'],
            ['use' => 'html', 'path' => 'd'],
            ['use' => 'Acme\\Slack', 'with' => ['channel' => '#ci']],
            ['use' => 'acme', 'path' => 'e.txt'],
        ],
        'uncovered' => 'exclude',
        'baseline' => ['path' => 'b.json', 'improvement' => 'report'],
        'packages' => ['packages/*'],
        'reach' => ['everything' => ['config/**']],
        'holds' => ['hotPath' => 0.5],
        'shards' => ['seconds' => 900, 'max' => 8],
        'costs' => ['secondsPerLine' => ['' => 0.3, 'src' => 1]],
        'ci' => [
            'plan' => 'gitlab',
            'defaultBranch' => 'trunk',
            'gitlab' => ['template' => 'ci.yml'],
            'buildkite' => ['step' => ['label' => 'm']],
        ],
        'proofs' => [
            'store' => [
                'use' => 's3',
                'with' => ['bucket' => 'bucket', 'region' => 'auto', 'endpoint' => 'https://r2'],
            ],
            'ignore' => ['docs/**'],
            'write' => 'never',
        ],
        'budget' => '15m',
        'timeouts' => ['mode' => 'unjudged', 'seconds' => 30, 'retries' => 5],
        'flaky' => ['confirmSurvivors' => false],
        'badge' => ['colors' => ['green' => 85]],
        'pest' => ['patch' => true, 'canary' => 'canary'],
        'staticCheck' => ['tool' => 'phpstan', 'config' => 'phpstan.dist.neon'],
        'local' => ['watchBudget' => '2m', 'prePushBudget' => '10m'],
    ]);
});

it('writes the memory cap beside the runner it chooses, and alone where a later layer chooses one', function (): void {
    $cap = MemoryCap::of(512, MemoryUnit::Megabytes);

    expect(Configs::written(Gate::configure()->runner(Runner::pest()->cappedAt($cap))))
        ->toBe(['runner' => ['use' => 'pest', 'memory' => '512M']])
        ->and(Configs::written(Gate::configure()->withholding(Withheld::of('DEPLOY_*'))->cappedAt(MemoryCap::none())))
        ->toBe(['runner' => ['withhold' => ['DEPLOY_*'], 'memory' => '-1']]);
});

it('writes each other way to choose an adapter or a word', function (): void {
    expect(Configs::written(
        Gate::configure()
            ->preset(Preset::symfony(), Preset::laravel(), Preset::named('acme'))
            ->runner(Runner::infection())
            ->treeSource(Source::phpunit('app', 'lib'))
            ->with(
                Uncovered::counted(),
                Baseline::requiringImprovement(),
                Ci::buildkite(),
                Proofs::directory('ledger'),
                Proofs::writing(),
                Timeouts::confirmed(),
                Flaky::confirmingSurvivors(),
                Ignores::refusingNativeMarkers(),
                Pest::unpatched(),
            ),
    ))->toBe([
        'preset' => ['symfony', 'laravel', 'acme'],
        'runner' => 'infection',
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app', 'lib']]],
        'uncovered' => 'count',
        'baseline' => ['improvement' => 'require'],
        'ci' => ['plan' => 'buildkite'],
        'proofs' => ['store' => ['use' => 'directory', 'with' => ['path' => 'ledger']], 'write' => 'auto'],
        'timeouts' => ['mode' => 'confirm'],
        'flaky' => ['confirmSurvivors' => true],
        'ignores' => ['native' => 'refuse'],
        'pest' => ['patch' => false],
    ]);
});

it('names each built-in adapter by a method of its own', function (Closure $gate, array $written): void {
    $built = $gate();

    expect($built instanceof Gate ? Configs::written($built) : $built)->toBe($written);
})->with([
    'pest' => [fn(): Gate => Gate::configure()->runner(Runner::pest()), ['runner' => 'pest']],
    'a runner by name' => [fn(): Gate => Gate::configure()->runner(Runner::uses('acme')), ['runner' => 'acme']],
    'phpunit' => [fn(): Gate => Gate::configure()->treeSource(Source::phpunit()), ['treeSource' => 'phpunit']],
    'a tree source by name' => [
        fn(): Gate => Gate::configure()->treeSource(Source::uses('acme', Option::of('depth', 2))),
        ['treeSource' => ['use' => 'acme', 'with' => ['depth' => 2]]],
    ],
    'github' => [fn(): Gate => Gate::configure()->with(Ci::github()), ['ci' => ['plan' => 'github']]],
    'circleci' => [fn(): Gate => Gate::configure()->with(Ci::circleci()), ['ci' => ['plan' => 'circleci']]],
    'azure' => [fn(): Gate => Gate::configure()->with(Ci::azure()), ['ci' => ['plan' => 'azure']]],
    'the Azure DevOps definition' => [
        fn(): Gate => Gate::configure()->with(Ci::azureDefinition('.azure/gate.yml')),
        ['ci' => ['azure' => ['definition' => '.azure/gate.yml']]],
    ],
    'the JSON plan' => [fn(): Gate => Gate::configure()->with(Ci::json()), ['ci' => ['plan' => 'json']]],
    'a CI plan by name' => [
        fn(): Gate => Gate::configure()->with(Ci::uses('acme', Option::of('x', 'y'))),
        ['ci' => ['plan' => ['use' => 'acme', 'with' => ['x' => 'y']]]],
    ],
    'the default directory' => [
        fn(): Gate => Gate::configure()->with(Proofs::directory()),
        ['proofs' => ['store' => 'directory']],
    ],
    's3 with its defaults' => [
        fn(): Gate => Gate::configure()->with(Proofs::s3('bucket')),
        ['proofs' => ['store' => ['use' => 's3', 'with' => ['bucket' => 'bucket']]]],
    ],
    's3 with every option' => [
        fn(): Gate => Gate::configure()->with(Proofs::s3('b', 'p', 'r', 'e')),
        ['proofs' => ['store' => [
            'use' => 's3',
            'with' => ['bucket' => 'b', 'prefix' => 'p', 'region' => 'r', 'endpoint' => 'e'],
        ]]],
    ],
    'a proof store by name' => [
        fn(): Gate => Gate::configure()->with(Proofs::uses('acme')),
        ['proofs' => ['store' => 'acme']],
    ],
    'no Buildkite step' => [
        fn(): Gate => Gate::configure()->with(Ci::buildkiteStep()),
        ['ci' => ['buildkite' => ['step' => []]]],
    ],
    'the analyser zero-config finds' => [
        fn(): Gate => Gate::configure()->with(StaticCheck::auto()),
        ['staticCheck' => ['tool' => 'auto']],
    ],
    'no analyser' => [
        fn(): Gate => Gate::configure()->with(StaticCheck::none()),
        ['staticCheck' => ['tool' => 'none']],
    ],
    'mago' => [fn(): Gate => Gate::configure()->with(StaticCheck::mago()), ['staticCheck' => ['tool' => 'mago']]],
    'psalm' => [fn(): Gate => Gate::configure()->with(StaticCheck::psalm()), ['staticCheck' => ['tool' => 'psalm']]],
    'an analyser by name' => [
        fn(): Gate => Gate::configure()->with(StaticCheck::uses('acme', Option::of('level', 9))),
        ['staticCheck' => ['tool' => ['use' => 'acme', 'with' => ['level' => 9]]]],
    ],
]);

it('lays each setting over those before it', function (): void {
    expect(Configs::written(
        Gate::configure()
            ->with(Proofs::s3('bucket'), Proofs::directory(), Reach::everything('a'), Reach::everything('b', 'a'))
            ->runner(Runner::pest())
            ->runner(Runner::infection())
            ->trees(Tree::at('a'))
            ->trees(Tree::at('b'))
            ->reporting(Report::json('a'))
            ->reporting(Report::json('b')),
    ))->toBe([
        'proofs' => ['store' => 'directory'],
        'reach' => ['everything' => ['a', 'b']],
        'runner' => 'infection',
        'trees' => [['path' => 'b']],
        'reports' => [['use' => 'json', 'path' => 'b']],
    ]);
});

it('writes lists as lists, whatever their arguments are named', function (): void {
    expect(Configs::written(Gate::configure()->with(
        Reach::packages(...['first' => 'packages/*']),
        Reach::everything(...['a' => 'config/**']),
        Proofs::ignore(...['a' => 'docs/**']),
    )->treeSource(Source::phpunit(...['a' => 'app']))))->toBe([
        'packages' => ['packages/*'],
        'reach' => ['everything' => ['config/**']],
        'proofs' => ['ignore' => ['docs/**']],
        'treeSource' => ['use' => 'phpunit', 'with' => ['fallback' => ['app']]],
    ])->and(Option::object(Option::list(...['key' => 'k', 'a' => 1, 'b' => 2]))->line())->toBe('{"k":[1,2]}');
});

it('writes options as the object they make', function (): void {
    expect(Option::object(Option::of('channel', '#ci'))->line())->toBe('{"channel":"#ci"}')
        ->and(Option::object()->line())->toBe('{}')
        ->and(Option::choice('pest'))->toBe('pest');
});

it('keeps a number as it was written, for the validator to judge', function (): void {
    expect(Configs::written(Gate::configure()->newCode(Floor::of(150.5))->trees(Tree::at('src', floor: -1))))->toBe([
        'newCode' => ['floor' => 150.5],
        'trees' => [['path' => 'src', 'floor' => -1]],
    ])->and(Floor::of(12)->percent())->toBe(12)
        ->and(Preset::named('acme')->name())->toBe('acme')
        ->and(Load::extension('Acme\\A')->class())->toBe('Acme\\A');
});

it('writes a value given empty, for the config to refuse, rather than leave it out', function (): void {
    $gate = Gate::configure()
        ->trees(Tree::at('gen', floor: 0, because: ''))
        ->ignoring(Ignore::mutant('3f9a1c2b7d04', because: 'Same', until: ''))
        ->reporting(Report::writing('acme', ''))
        ->with(Proofs::s3('bucket', region: ''));

    expect(Configs::problems($gate->layer(ProjectRoot::origin())))->toBe([
        'trees[0].reason: expected a reason, got ""',
        'proofs.store.with.region: expected a region, got ""',
        'ignores.entries[0].expires: expected a date written YYYY-MM-DD, got ""',
        'reports[0].path: expected a path, got ""',
    ]);
});

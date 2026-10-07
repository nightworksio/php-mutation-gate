<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Choice;
use NightWorksIO\MutationGate\Core\Config\Definition;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;

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

it('refuses a timeouts.most under timeouts.seconds, however the layers lay them', function (array $config, string $problem): void {
    expect(Configs::problems(Configs::validated(['runner' => 'pest', ...$config])))->toBe([$problem]);
})->with([
    'both in the config' => [
        ['timeouts' => ['seconds' => 30, 'most' => 29]],
        'timeouts.most: expected at least timeouts.seconds, 30, got 29',
    ],
    'a most under the standard floor' => [['timeouts' => ['most' => 9]], 'timeouts.most: expected at least timeouts.seconds, 10, got 9'],
    'a floor over the standard most' => [
        ['timeouts' => ['seconds' => 301]],
        'timeouts.most: expected at least timeouts.seconds, 301, got 300',
    ],
]);

it('takes a timeouts.most equal to timeouts.seconds', function (): void {
    $settings = Configs::settings(['runner' => 'pest', 'timeouts' => ['seconds' => 30, 'most' => 30]]);

    expect($settings->triage()->bounds())
        ->toEqual(LimitBounds::between(Seconds::of(30.0), Seconds::of(30.0))->tighterFor(TighterSilence::standard()));
});

it('refuses a config that is not an object', function (string $json, string $problem): void {
    expect(Configs::problems(Configs::validated($json)))->toBe([$problem]);
})->with([
    'a list' => ['[{"runner": "pest"}]', ': expected an object, got a list'],
    'a number' => ['3', ': expected an object, got 3'],
]);

it('refuses a string where a number belongs, and a fraction where an integer does', function (): void {
    expect(Configs::problems(Configs::validated(
        '{"runner": "pest", "newCode": {"floor": "100"}, "timeouts": {"seconds": 10.0, "most": 0}, '
        . '"flaky": {"confirmSurvivors": 1}, "staticCheck": {"seconds": 2.5}}',
    )))->toBe([
        'newCode.floor: expected a number from 0 to 100, got "100"',
        'timeouts.seconds: expected an integer of at least 1, got 10.0',
        'timeouts.most: expected an integer of at least 1, got 0',
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
        'timeouts' => ['seconds' => 1, 'most' => 1],
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

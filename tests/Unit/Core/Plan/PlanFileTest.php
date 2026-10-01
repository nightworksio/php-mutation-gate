<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Undigested;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/** The full id of the commit every plan here is made on. */
function planFileCommit(): string
{
    return '5eeca8f2d0b1c4e7a9f3b6d8e0c2a4f6b8d0e2c4';
}

/** A plan on that commit that considered nothing. */
function planFileEmpty(): Plan
{
    return Plan::of(Revision::ref(planFileCommit()), Digest::sha256Of('base'), Keys::none(), Shards::none());
}

/** A plan file on that commit, the rest of whose fields are these. */
function planFileWith(string $fields): string
{
    return sprintf('{"format": 1, "commit": "%s", %s}', planFileCommit(), $fields);
}

$planWith = static fn(string $secondLabel): Plan => Plan::of(
    Revision::ref(planFileCommit()),
    Digest::sha256Of('base'),
    Keys::none()
        ->with(Path::of('src/A.php'), Digest::of('aaa'))
        ->with(Path::of('src/B.php'), Unkeyed::because('No coverage map.'))
        ->with(Path::of('src/C.php'), Digest::of('ccc')),
    Shards::of(
        Shard::of(
            ShardId::of(1),
            Package::at(Path::root()),
            Units::of(
                Unit::file(Path::of('src/A.php')),
                Unit::held(Path::of('src/Kernel.php'), Group::named('holds:kernel')),
            ),
            Seconds::of(12.5),
            'src, part 1 of 2',
        ),
        Shard::of(
            ShardId::of(2),
            Package::at(Path::of('packages/b')),
            Units::of(Unit::file(Path::of('src/B.php'))),
            Seconds::of(3.0),
            $secondLabel,
        ),
    ),
)->on(RunOn::at(Scope::pullRequest(12), Scope::branch('main')));

$body = <<<'JSON'
{
    "format": 1,
    "commit": "5eeca8f2d0b1c4e7a9f3b6d8e0c2a4f6b8d0e2c4",
    "base": "cae662172fd450bb0cd710a769079c05bfc5d8e35efa6576edc7d0377afdd4a2",
    "ref": "refs/pull/12",
    "defaultBranch": "refs/heads/main",
    "keys": {
        "src/A.php": "aaa",
        "src/B.php": {
            "unkeyed": "No coverage map."
        },
        "src/C.php": "ccc"
    },
    "shards": [
        {
            "id": 1,
            "label": "src, part 1 of 2",
            "seconds": 12.5,
            "package": ".",
            "units": [
                {
                    "path": "src/A.php"
                },
                {
                    "path": "src/Kernel.php",
                    "group": "holds:kernel"
                }
            ]
        },
        {
            "id": 2,
            "label": "src, part 2 of 2",
            "seconds": 3.0,
            "package": "packages/b",
            "units": [
                {
                    "path": "src/B.php"
                }
            ]
        }
    ]
}
JSON;

it('writes the commit, base, ref and default branch, every key, the shards and their digest', function () use (
    $planWith,
    $body,
): void {
    expect(PlanFile::encode($planWith('src, part 2 of 2')))->toBe(sprintf(
        "%s,\n    \"unnamed\": \"The plan names no test.\",\n    \"digest\": \"%s\"\n}",
        mb_substr($body, 0, -2),
        hash('sha256', $body),
    ));
});

it('takes its digest over everything it holds', function () use ($planWith, $body): void {
    expect(PlanFile::digestOf($planWith('src, part 2 of 2')))->toEqual(Digest::of(hash('sha256', $body)))
        ->and(PlanFile::digestOf($planWith('src, the rest')))->not->toEqual(Digest::of(hash('sha256', $body)));
});

it('writes a plan with nothing considered as an empty map of keys and no shards', function (): void {
    $plan = planFileEmpty();
    $empty = sprintf(<<<'JSON'
        {
            "format": 1,
            "commit": "%s",
            "base": "%s",
            "keys": {},
            "shards": []
        }
        JSON, planFileCommit(), hash('sha256', 'base'));

    expect(PlanFile::encode($plan))->toBe(sprintf(
        "%s,\n    \"unnamed\": \"The plan names no test.\",\n    \"digest\": \"%s\"\n}",
        mb_substr($empty, 0, -2),
        hash('sha256', $empty),
    ));
});

it('writes the names the runner gives the tests beside the plan, outside its digest, and reads them back', function () use (
    $planWith,
): void {
    $test = TestName::in(Path::of('tests/MoneyTest.php'), 'it adds');
    $names = TestNames::none()
        ->with(TestId::of('MoneyTest::adds'), $test)
        ->with(TestId::of('MoneyTest::adds#one'), TestRow::of($test, '"one"'));
    $plan = $planWith('src, part 2 of 2');
    $named = $plan->naming($names);
    $written = PlanFile::encode($named);
    $namesWritten = <<<'JSON'
            "names": {
                "MoneyTest::adds": {
                    "file": "tests/MoneyTest.php",
                    "description": "it adds"
                },
                "MoneyTest::adds#one": {
                    "file": "tests/MoneyTest.php",
                    "description": "it adds",
                    "row": "\"one\""
                }
            },
        JSON;

    expect($written)->toContain($namesWritten)
        ->and($written)->not->toContain('"unnamed"')
        ->and(PlanFile::decode($written))->toEqual($named)
        ->and(PlanFile::digestOf($named))->toEqual(PlanFile::digestOf($plan))
        ->and(PlanFile::digestOf($plan->naming(CannotJudge::because('Pest cannot list.'))))
        ->toEqual(PlanFile::digestOf($plan));
});

it('writes why the runner named no test, and reads it back', function (): void {
    $plan = planFileEmpty()
        ->on(RunOn::detached(Scope::branch('main')))
        ->naming(CannotJudge::because('Pest cannot list its tests.'));
    $written = PlanFile::encode($plan);

    expect($written)->toContain('"unnamed": "Pest cannot list its tests."')
        ->and(PlanFile::decode($written))->toEqual($plan);
});

it('reads a plan that holds no names, nor why, as one made without asking', function (): void {
    $plan = planFileEmpty()->on(RunOn::detached(Scope::branch('main')));
    $written = PlanFile::encode($plan);
    $bare = str_replace("\n    \"unnamed\": \"The plan names no test.\",", '', $written);
    $read = PlanFile::decode($bare);

    expect($bare)->not->toContain('unnamed')
        ->and($read)->toEqual($plan)
        ->and($read instanceof Plan ? $read->names() : $read)->toEqual(CannotJudge::because('The plan names no test.'));
});

it('reads back the plan it wrote', function () use ($planWith): void {
    $plan = $planWith('src, part 2 of 2');

    expect(PlanFile::decode(PlanFile::encode($plan)))->toEqual($plan);
});

it('reads back a plan for a detached HEAD, with or without a default branch', function (RunOn $runOn): void {
    $plan = planFileEmpty()->on($runOn);

    expect(PlanFile::decode(PlanFile::encode($plan)))->toEqual($plan);
})->with([
    'a default branch' => [RunOn::detached(Scope::branch('main'))],
    'none' => [RunOn::detached(CannotTell::because('The plan names no default branch.'))],
]);

it('reads a plan that names no default branch as one that cannot tell it', function (): void {
    $plan = PlanFile::decode(PlanFile::encode(planFileEmpty()));

    expect($plan instanceof Plan ? $plan->runOn()->defaultBranch() : $plan)
        ->toEqual(CannotTell::because('The plan names no default branch.'))
        ->and($plan instanceof Plan ? $plan->runOn()->scope() : $plan)->toEqual(Detached::head());
});

it('writes and reads back the lines a change added or modified, and why it reached what it did', function (): void {
    $plan = planFileEmpty()->on(RunOn::detached(Scope::branch('main')))->considering(
        Considered::everything()->reaching(
            Changes::of(
                Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3), Line::of(4))),
                Change::modified(Path::of('src/Renamed.php'), Lines::none()),
            ),
            Reasons::of(Reason::that('`src/Money.php` changed, so its unit is reached.')),
        ),
    );
    $written = PlanFile::encode($plan);

    $changed = <<<'JSON'
        "changed": {
                "src/Money.php": [
                    3,
                    4
                ],
        JSON;

    expect($written)->toContain($changed)
        ->and($written)->toContain("\"reach\": [\n        \"`src/Money.php` changed, so its unit is reached.\"\n    ]")
        ->and(PlanFile::decode($written))->toEqual($plan);
});

it('writes neither changed lines nor reasons for a full run', function (): void {
    $written = PlanFile::encode(planFileEmpty());

    expect($written)->not->toContain('"changed"')
        ->and($written)->not->toContain('"reach"');
});

it('writes the reasons of a change that changed no source line', function (): void {
    $plan = planFileEmpty()
        ->on(RunOn::detached(Scope::branch('main')))
        ->considering(Considered::everything()->reaching(Changes::none(), Reasons::of(Reason::that('Nothing reached.'))));

    expect(PlanFile::decode(PlanFile::encode($plan)))->toEqual($plan);
});

it('refuses a plan changed after it was made', function () use ($planWith): void {
    $written = PlanFile::encode($planWith('src, part 2 of 2'));
    $changed = str_replace('"label": "src, part 2 of 2"', '"label": "src"', $written);

    expect(PlanFile::decode($changed))->toEqual(CannotJudge::because(
        'The plan does not match its digest, so it was changed after it was made. Plan again.',
    ));
});

it('refuses what is not a plan, saying where it went wrong', function (string $json, string $where): void {
    expect(PlanFile::decode($json))
        ->toEqual(CannotJudge::because(sprintf('The plan cannot be read, so no shard can follow it: %s', $where)));
})->with([
    'not JSON' => ['not a plan', 'the file.format is missing.'],
    'another format' => ['{"format": 2}', 'the file.format is not format 1.'],
    'shards that are not a list' => ['{"format": 1, "shards": {"a": 1}}', 'the file.shards is not a list.'],
    'a shard numbered nought' => [
        '{"format": 1, "shards": [{"id": 0, "label": "src", "seconds": 1, "package": ".", "units": []}]}',
        'the file.shards[0].id is not a shard number.',
    ],
    'a shard with no label' => [
        '{"format": 1, "shards": [{"id": 1, "seconds": 1, "package": ".", "units": []}]}',
        'the file.shards[0].label is missing.',
    ],
    'no commit' => ['{"format": 1, "shards": []}', 'the file.commit is missing.'],
    'a ref that is not a scope' => [
        planFileWith('"base": "b", "ref": "main", "keys": {}, "shards": []'),
        'the file.ref is not a scope.',
    ],
    'a default branch that is not a scope' => [
        planFileWith('"base": "b", "defaultBranch": "main", "keys": {}, "shards": []'),
        'the file.defaultBranch is not a scope.',
    ],
    'a changed line before the first' => [
        planFileWith('"base": "b", "keys": {}, "shards": [], "changed": {"a.php": [0]}, "reach": []'),
        'the file.changed.a.php[0] is not a line.',
    ],
    'a reason that is not text' => [
        planFileWith('"base": "b", "keys": {}, "shards": [], "changed": {}, "reach": [3]'),
        'the file.reach[0] is not text.',
    ],
    'a commit that is not a full id' => [
        '{"format": 1, "commit": "5eeca8f", "base": "b", "keys": {}, "shards": []}',
        'the file.commit is not a commit.',
    ],
    'no base' => [planFileWith('"keys": {}, "shards": []'), 'the file.base is missing.'],
    'no digest' => [
        planFileWith('"base": "b", "keys": {}, "shards": []'),
        'the file.digest is missing.',
    ],
]);

it('writes and reads back the units it proved and those it carries, and neither where none are', function (): void {
    $empty = planFileEmpty();
    $plan = $empty
        ->on(RunOn::detached(Scope::branch('main')))
        ->considering(Considered::everything()->proving(Units::of(Unit::file(Path::of('src/A.php'))))->carrying(
            Units::of(Unit::held(Path::of('src/Kernel'), Group::named('holds:src/Kernel')), Unit::file(Path::of('src/B.php'))),
        ));
    $written = PlanFile::encode($plan);
    $none = PlanFile::encode($empty);

    expect($written)->toContain("\"proved\": [\n        {\n            \"path\": \"src/A.php\"\n        }\n    ],")
        ->and($written)->toContain(implode("\n", [
            '"carried": [',
            '        {',
            '            "path": "src/Kernel",',
            '            "group": "holds:src/Kernel"',
            '        },',
        ]))
        ->and(PlanFile::decode($written))->toEqual($plan)
        ->and($none)->not->toContain('"proved"')
        ->and($none)->not->toContain('"carried"');
});

it('writes the digests of the run\'s inputs within its digest, and reads them back', function (): void {
    $digests = Digests::of(Digest::sha256Of('mutation'))
        ->withSource(Path::of('src/A.php'), Digest::sha256Of('a'))
        ->withTest(Path::of('tests/ATest.php'), Digest::sha256Of('a test'));
    $plan = planFileEmpty()->on(RunOn::at(Scope::branch('main'), Scope::branch('main')))->digesting($digests);
    $written = PlanFile::encode($plan);
    $read = PlanFile::decode($written);

    expect($read)->toEqual($plan)
        ->and($read instanceof Plan ? $read->digests() : $read)->toEqual($digests)
        ->and($plan->digest())->not->toEqual(planFileEmpty()->digest())
        ->and($written)->toContain(sprintf('"src/A.php": "%s"', Digest::sha256Of('a')->value()))
        ->and(planFileEmpty()->digests())->toEqual(Undigested::proof())
        ->and(PlanFile::encode(planFileEmpty()))->not->toContain('"digests"');
});

it('writes the commit the run\'s digests were taken at, and reads it back', function (): void {
    $commit = Revision::ref(str_repeat('c0', 20));
    $plan = planFileEmpty()->on(RunOn::at(Scope::branch('main'), Scope::branch('main')))
        ->digesting(Digests::of(Digest::sha256Of('mutation'))->takenAt($commit));
    $read = PlanFile::decode(PlanFile::encode($plan));

    expect($read)->toEqual($plan)
        ->and($read instanceof Plan ? $read->digests() : $read)->toEqual(Digests::of(Digest::sha256Of('mutation'))->takenAt($commit))
        ->and(PlanFile::encode($plan))->toContain(sprintf('"commit": "%s"', str_repeat('c0', 20)))
        ->and(PlanFile::decode(str_replace(str_repeat('c0', 20), 'HEAD', PlanFile::encode($plan))))
        ->toBeInstanceOf(CannotJudge::class);
});

it('refuses a plan whose digests are not well formed', function (): void {
    $plan = planFileEmpty()->digesting(Digests::of(Digest::sha256Of('mutation')));
    $spoiled = str_replace(Digest::sha256Of('mutation')->value(), 'abc', PlanFile::encode($plan));

    expect(PlanFile::decode($spoiled))->toBeInstanceOf(CannotJudge::class);
});

it('reads back digests of a run with no unit and no test file', function (): void {
    $plan = planFileEmpty()->on(RunOn::at(Scope::branch('main'), Scope::branch('main')))->digesting(Digests::of(Digest::sha256Of('mutation')));

    expect(PlanFile::decode(PlanFile::encode($plan)))->toEqual($plan);
});

it('reads back a change to a file whose path reads as a number', function (): void {
    $plan = planFileEmpty()->on(RunOn::detached(Scope::branch('main')))->considering(
        Considered::everything()->reaching(
            Changes::of(Change::modified(Path::of('7'), Lines::of(Line::of(3)))),
            Reasons::of(Reason::that('`7` changed, so its unit is reached.')),
        ),
    );

    expect(PlanFile::decode(PlanFile::encode($plan)))->toEqual($plan);
});

it('writes the suite\'s measured peak within its digest, in bytes, and reads it back', function (): void {
    $plan = planFileEmpty()->on(RunOn::at(Scope::branch('main'), Scope::branch('main')))
        ->weighing(MemoryCap::of(300, MemoryUnit::Megabytes));
    $written = PlanFile::encode($plan);
    $read = PlanFile::decode($written);

    expect($read)->toEqual($plan)
        ->and($read instanceof Plan ? $read->peak() : $read)->toEqual(MemoryCap::of(300, MemoryUnit::Megabytes))
        ->and($written)->toContain('"peak": 314572800')
        ->and($plan->digest())->not->toEqual($plan->weighing(NotGiven::value())->digest());
});

it('reads a plan that holds no peak, as one made before the plan measured it, as having measured none', function (): void {
    $plan = planFileEmpty()->on(RunOn::at(Scope::branch('main'), Scope::branch('main')));
    $read = PlanFile::decode(PlanFile::encode($plan));

    expect(PlanFile::encode($plan))->not->toContain('"peak"')
        ->and($read instanceof Plan ? $read->peak() : $read)->toEqual(NotGiven::value());
});

it('refuses a plan whose peak was changed after it was made, or is no number of bytes', function (): void {
    $plan = planFileEmpty()->on(RunOn::at(Scope::branch('main'), Scope::branch('main')))
        ->weighing(MemoryCap::of(300, MemoryUnit::Megabytes));
    $written = PlanFile::encode($plan);

    expect(PlanFile::decode(str_replace('"peak": 314572800', '"peak": 1048576', $written)))->toEqual(CannotJudge::because(
        'The plan does not match its digest, so it was changed after it was made. Plan again.',
    ))->and(PlanFile::decode(str_replace('"peak": 314572800', '"peak": 0', $written)))->toEqual(CannotJudge::because(
        'The plan cannot be read, so no shard can follow it: the file.peak is not a number of bytes.',
    ));
});

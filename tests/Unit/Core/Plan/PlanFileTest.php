<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanFile;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$planWith = static fn(string $secondLabel): Plan => Plan::of(
    Revision::ref('5eeca8f'),
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
);

$body = <<<'JSON'
{
    "format": 1,
    "commit": "5eeca8f",
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

it('writes the commit, every key, the shards and the digest of all of them', function () use ($planWith, $body): void {
    expect(PlanFile::encode($planWith('src, part 2 of 2')))
        ->toBe(sprintf("%s,\n    \"digest\": \"%s\"\n}", mb_substr($body, 0, -2), hash('sha256', $body)));
});

it('takes its digest over everything it holds', function () use ($planWith, $body): void {
    expect(PlanFile::digestOf($planWith('src, part 2 of 2')))->toEqual(Digest::of(hash('sha256', $body)))
        ->and(PlanFile::digestOf($planWith('src, the rest')))->not->toEqual(Digest::of(hash('sha256', $body)));
});

it('writes a plan with nothing considered as an empty map of keys and no shards', function (): void {
    $plan = Plan::of(Revision::ref('5eeca8f'), Keys::none(), Shards::none());
    $empty = "{\n    \"format\": 1,\n    \"commit\": \"5eeca8f\",\n    \"keys\": {},\n    \"shards\": []\n}";

    expect(PlanFile::encode($plan))
        ->toBe(sprintf("%s,\n    \"digest\": \"%s\"\n}", mb_substr($empty, 0, -2), hash('sha256', $empty)));
});

it('reads back the plan it wrote', function () use ($planWith): void {
    $plan = $planWith('src, part 2 of 2');

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
    'no digest' => ['{"format": 1, "commit": "5eeca8f", "keys": {}, "shards": []}', 'the file.digest is missing.'],
]);

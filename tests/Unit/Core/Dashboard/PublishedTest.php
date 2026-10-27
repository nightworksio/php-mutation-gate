<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Dashboard\Published;
use NightWorksIO\MutationGate\Core\Dashboard\PublishedTree;
use NightWorksIO\MutationGate\Core\Dashboard\TreeBelow;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Tests\Support\Dashboards;

it('reads what a repository published: its commit, time, verdict, score, trees, floor for new code and savings', function (): void {
    $published = Published::read(Dashboards::summary());

    expect($published)->toBeInstanceOf(Published::class);

    if (! $published instanceof Published) {
        return;
    }

    expect($published->commit())->toBe('3f9a1c2')
        ->and($published->time()->value())->toBe('2026-09-30T10:00:00Z')
        ->and($published->verdict())->toBe(Judgement::Failed)
        ->and($published->score())->toEqual(Score::ofHundredths(4_444))
        ->and(array_map(static fn(PublishedTree $tree): string => $tree->path(), $published->trees()))->toBe(['src', 'app/Legacy', 'src/Empty'])
        ->and($published->trees()[1]->floor())->toEqual(Undeclared::floor())
        ->and($published->trees()[1]->score())->toEqual(NothingToMutate::found())
        ->and($published->trees()[2]->floor())->toEqual(Floor::of(90))
        ->and($published->newCode())->toEqual(Floor::of(95))
        ->and($published->saved())->toEqual(Seconds::of(147_600.0))
        ->and(array_map(static fn(TreeBelow $tree): array => [$tree->path(), $tree->floor()->percent(), $tree->score()->percent()], $published->belowFloor()))
        ->toBe([['src', 80.0, 44.44]]);
});

it('reads a project with nothing to mutate and no history of what it saved', function (): void {
    $published = Published::read('{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}}');

    expect($published instanceof Published ? $published->score() : $published)->toEqual(NothingToMutate::found())
        ->and($published instanceof Published ? $published->saved() : $published)->toEqual(NoHistory::yet())
        ->and($published instanceof Published ? $published->verdict() : $published)->toBe(Judgement::Passed)
        ->and($published instanceof Published ? $published->belowFloor() : $published)->toBe([]);
});

it('refuses whole what is not a summary in the shape of format 1, and says why', function (string $json, string $why): void {
    expect(Published::read($json))->toEqual(CannotJudge::because($why));
})->with([
    'not JSON' => ['<html>', 'Its summary.json is not one this gate reads: summary.json.format is missing.'],
    'a later format' => ['{"format": 2}', 'Its summary.json is format 2, and this gate reads format 1.'],
    'a verdict no verdict is' => ['{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "nothing-to-mutate", "trees": [], "newCode": {"floor": 100}}', 'Its summary.json is not one this gate reads: summary.json.verdict is not passed, failed or cannot-judge.'],
    'a time not in UTC' => ['{"format": 1, "commit": "a", "time": "yesterday", "verdict": "passed", "trees": [], "newCode": {"floor": 100}}', 'Its summary.json is not one this gate reads: summary.json.time is not an instant in UTC.'],
    'a score past 100' => ['{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "score": 101, "trees": [], "newCode": {"floor": 100}}', 'Its summary.json is not one this gate reads: summary.json.score is not a percentage from 0 to 100.'],
    'a tree\'s floor below 0' => ['{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [{"path": "src", "floor": -1}], "newCode": {"floor": 100}}', 'Its summary.json is not one this gate reads: summary.json.trees[0].floor is not a percentage from 0 to 100.'],
    'savings below nothing' => ['{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}, "savings": {"last30Days": -5}}', 'Its summary.json is not one this gate reads: summary.json.savings.last30Days is not a number of seconds.'],
    'no floor for new code' => ['{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": []}', 'Its summary.json is not one this gate reads: summary.json.newCode.floor is missing.'],
]);

it('says a tree is below its floor only where it scores less than a floor it is held to', function (Floor|Undeclared $floor, Score|NothingToMutate $score, bool $below): void {
    expect(PublishedTree::of('src', $floor, $score)->below() instanceof TreeBelow)->toBe($below);
})->with([
    'below' => [fn(): Floor => Floor::of(80), fn(): Score => Score::ofHundredths(7_999), true],
    'at it' => [fn(): Floor => Floor::of(80), fn(): Score => Score::ofHundredths(8_000), false],
    'held to none' => [fn(): Undeclared => Undeclared::floor(), fn(): Score => Score::ofHundredths(0), false],
    'nothing to mutate' => [fn(): Floor => Floor::of(80), fn(): NothingToMutate => NothingToMutate::found(), false],
]);

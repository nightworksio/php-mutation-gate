<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\SavedLately;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\Dashboard\Published;
use NightWorksIO\MutationGate\Core\Dashboard\PublishedFile;
use NightWorksIO\MutationGate\Core\Dashboard\PublishedMutator;
use NightWorksIO\MutationGate\Core\Dashboard\PublishedTree;
use NightWorksIO\MutationGate\Core\Dashboard\TreeBelow;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Percentage;
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
        ->and($published->saved())->toEqual(SavedLately::of(Seconds::of(147_600.0), SavingsWindow::standard()))
        ->and(array_map(static fn(TreeBelow $tree): array => [$tree->path(), $tree->floor()->percent(), $tree->score()->percent()], $published->belowFloor()))
        ->toBe([['src', 80.0, 44.44]]);
});

it('reads a project with nothing to mutate and no history of what it saved', function (): void {
    $published = Published::read('{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}}');

    expect($published instanceof Published ? $published->score() : $published)->toEqual(NothingToMutate::found())
        ->and($published instanceof Published ? $published->saved() : $published)->toEqual(NoHistory::yet())
        ->and($published instanceof Published ? $published->verdict() : $published)->toBe(Judgement::Passed)
        ->and($published instanceof Published ? $published->belowFloor() : $published)->toBe([])
        ->and($published instanceof Published ? $published->mutators() : $published)->toBe([])
        ->and($published instanceof Published ? $published->files() : $published)->toBe([]);
});

it('reads the lowest-scoring files a repository named, each with its base where it gave one', function (): void {
    $published = Published::read('{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}, '
        . '"heatmap": [{"path": "src/A.php", "score": 40, "base": 60, "notKilled": 3}, {"path": "src/B.php", "notKilled": 0}]}');
    $read = $published instanceof Published ? array_map(
        static fn(PublishedFile $file): array => [$file->path(), $file->score(), $file->base(), $file->notKilled()],
        $published->files(),
    ) : $published;

    expect($read)->toEqual([
        ['src/A.php', Score::ofHundredths(4_000), Score::ofHundredths(6_000), 3],
        ['src/B.php', NothingToMutate::found(), NotGiven::value(), 0],
    ]);
});

it('reads the costliest mutators a repository named, each with its kill rate where it gave one', function (): void {
    $published = Published::read('{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}, '
        . '"mutators": [{"runner": "pest", "mutator": "Plus", "seconds": 90.5, "survived": 2, "killRate": 75}, {"runner": "infection", "mutator": "Minus", "seconds": 3, "survived": 0}]}');
    $read = $published instanceof Published ? array_map(
        static fn(PublishedMutator $mutator): array => [$mutator->runner(), $mutator->mutator(), $mutator->seconds(), $mutator->survived(), $mutator->killRate()],
        $published->mutators(),
    ) : $published;

    expect($read)->toEqual([
        ['pest', 'Plus', Seconds::of(90.5), 2, Percentage::parse(75)],
        ['infection', 'Minus', Seconds::of(3.0), 0, NotGiven::value()],
    ]);
});

it('reads a run that could not judge, saved nothing, and left every tree below its floor', function (): void {
    $published = Published::read('{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "cannot-judge", "score": 10, '
        . '"trees": [{"path": "src", "floor": 80, "score": 10}, {"path": "lib", "floor": 50, "score": 20}], "newCode": {"floor": 100}, "savings": {"seconds": 0, "window": "7d"}}');

    expect($published instanceof Published ? $published->verdict() : $published)->toBe(Judgement::CannotJudge)
        ->and($published instanceof Published ? $published->saved() : $published)->toEqual(SavedLately::of(Seconds::of(0.0), SavingsWindow::of(Seconds::days(7))))
        ->and($published instanceof Published ? array_map(static fn(TreeBelow $tree): string => $tree->path(), $published->belowFloor()) : $published)
        ->toBe(['src', 'lib']);
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
    'savings below nothing' => ['{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}, "savings": {"seconds": -1, "window": "30d"}}', 'Its summary.json is not one this gate reads: summary.json.savings.seconds is not a number of seconds.'],
    'savings in no window' => ['{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}, "savings": {"seconds": 1, "window": "a month"}}', 'Its summary.json is not one this gate reads: summary.json.savings.window is not a duration.'],
    'a mutator with no runner' => ['{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}, "mutators": [{"mutator": "Plus", "seconds": 1, "survived": 0}]}', 'Its summary.json is not one this gate reads: summary.json.mutators[0].runner is missing.'],
    'a mutator with fewer than no survivors' => ['{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}, "mutators": [{"runner": "pest", "mutator": "Plus", "seconds": 1, "survived": -1}]}', 'Its summary.json is not one this gate reads: summary.json.mutators[0].survived is not a count.'],
    'a file with fewer than none not killed' => ['{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}, "heatmap": [{"path": "src/A.php", "notKilled": -1}]}', 'Its summary.json is not one this gate reads: summary.json.heatmap[0].notKilled is not a count.'],
    'a file with no path' => ['{"format": 1, "commit": "a", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "trees": [], "newCode": {"floor": 100}, "heatmap": [{"notKilled": 0}]}', 'Its summary.json is not one this gate reads: summary.json.heatmap[0].path is missing.'],
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

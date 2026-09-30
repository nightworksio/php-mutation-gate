<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Judged;

$trees = static fn(Baseline $baseline): array => array_map(
    static fn(Entry $entry): string => sprintf('%s %d', $entry->tree()->value(), $entry->floor()->hundredths()),
    iterator_to_array($baseline, preserve_keys: true),
);
$verdict = static fn(string $path, Floor|Exempt|Undeclared $declared, Floor|Unrecorded $baseline, JudgedMutants $mutants): TreeVerdict => TreeVerdict::judged(
    Tree::at(Path::of($path), $declared, Package::at(Path::root())),
    $baseline,
    JudgedUnits::none(),
    $mutants,
    Uncovered::Count,
);
// Three killed of four: 75%.
$threeOfFour = Judged::mutants(MutantJudgement::Killed, MutantJudgement::Killed, MutantJudgement::Killed, MutantJudgement::Survived);

it('holds nothing to begin with', function (): void {
    expect(Baseline::none())->toHaveCount(0);
});

it('keeps one entry per tree, in byte order of the paths, numbered from nought', function () use ($trees): void {
    $baseline = Baseline::of(
        Entry::of(Path::of('app/b'), Floor::of(50)),
        Entry::of(Path::of('app/B'), Floor::of(60)),
        Entry::of(Path::of('app/a'), Floor::of(70)),
        Entry::of(Path::of('app/b'), Floor::of(80)),
    );

    expect($trees($baseline))->toBe(['app/B 6000', 'app/a 7000', 'app/b 8000']);
});

it('answers the entry and the floor of a tree, or that it has none', function (): void {
    $entry = Entry::of(Path::of('app/Http'), Floor::of(83.41));
    $baseline = Baseline::of(Entry::of(Path::of('app'), Floor::of(10)), $entry);

    expect($baseline->entryOf(Path::of('app/Http')))->toBe($entry)
        ->and($baseline->floorOf(Path::of('app/Http')))->toEqual(Floor::of(83.41))
        ->and($baseline->entryOf(Path::of('app/Domain')))->toEqual(Unrecorded::floor())
        ->and($baseline->floorOf(Path::of('app/Domain')))->toEqual(Unrecorded::floor());
});

it('adds an entry without changing the baseline it came from', function () use ($trees): void {
    $baseline = Baseline::of(Entry::of(Path::of('b'), Floor::of(50)));

    expect($trees($baseline->with(Entry::of(Path::of('a'), Floor::of(40)))))->toBe(['a 4000', 'b 5000'])
        ->and($baseline)->toHaveCount(1);
});

it('raises each floor a verdict raised to its score, dropping its lowered, and keeps the rest', function () use ($trees, $verdict, $threeOfFour): void {
    $baseline = Baseline::of(
        Entry::of(Path::of('app/Legacy'), Floor::of(61.2))->lowered(Lowered::from(Floor::of(64.5), 'Removed together.')),
        Entry::of(Path::of('app/Http'), Floor::of(90)),
        Entry::of(Path::of('app/Gone'), Floor::of(20)),
    );
    $raised = $baseline->raisedBy(TreeVerdicts::of(
        $verdict('app/Legacy', Undeclared::floor(), Floor::of(61.2), $threeOfFour),
        $verdict('app/Http', Undeclared::floor(), Floor::of(90), $threeOfFour),
        $verdict('app/New', Undeclared::floor(), Unrecorded::floor(), $threeOfFour),
        $verdict('app/Declared', Floor::of(100), Unrecorded::floor(), Judged::mutants(MutantJudgement::Killed)),
    ));

    expect($trees($raised))->toBe(['app/Gone 2000', 'app/Http 9000', 'app/Legacy 7500', 'app/New 7500'])
        ->and($raised->entryOf(Path::of('app/Legacy')))->toEqual(Entry::of(Path::of('app/Legacy'), Floor::of(75)))
        ->and($trees($baseline))->toBe(['app/Gone 2000', 'app/Http 9000', 'app/Legacy 6120']);
});

it('replaces the entry of a tree whose path reads as a number, as it does any other', function (): void {
    $baseline = Baseline::none()
        ->with(Entry::of(Path::of('12'), Floor::of(80)))
        ->with(Entry::of(Path::of('src'), Floor::of(70)))
        ->with(Entry::of(Path::of('12'), Floor::of(85)));

    expect($baseline)->toHaveCount(2)
        ->and($baseline->floorOf(Path::of('12')))->toEqual(Floor::of(85))
        ->and($baseline->floorOf(Path::of('src')))->toEqual(Floor::of(70));
});

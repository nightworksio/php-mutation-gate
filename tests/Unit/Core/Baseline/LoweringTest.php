<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Lowering;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;

$base = Baseline::of(Entry::of(Path::of('app/Http'), Floor::of(83.41)), Entry::of(Path::of('app/Domain'), Floor::of(100)));
$trees = Trees::of(
    Tree::at(Path::of('app/Http'), Undeclared::floor(), Package::at(Path::root())),
    Tree::at(Path::of('app/Domain'), Undeclared::floor(), Package::at(Path::root())),
);

it('passes floors that held or rose, and a floor lowered from the base\'s with a reason', function (Baseline $head) use ($base, $trees): void {
    expect(Lowering::against($base, $head, $trees))->toEqual(Failures::none());
})->with([
    'unchanged' => [$base],
    'raised' => [$base->with(Entry::of(Path::of('app/Http'), Floor::of(90)))],
    'a tree added' => [$base->with(Entry::of(Path::of('app/New'), Floor::of(10)))],
    'lowered with a reason' => [$base->with(
        Entry::of(Path::of('app/Http'), Floor::of(80))->lowered(Lowered::from(Floor::of(83.41), 'The export went.')),
    )],
]);

it('fails a floor lowered with no reason', function (Entry $entry) use ($base, $trees): void {
    expect(Lowering::against($base, $base->with($entry), $trees))->toEqual(Failures::of(Failure::that(
        "The floor of app/Http went down from 83.41 to 83.4 with no reason. A floor goes down only on purpose:\n"
        . 'add "lowered": { "from": 83.41, "reason": "…" } to its entry in the baseline.',
    )));
})->with([
    'no lowered' => [Entry::of(Path::of('app/Http'), Floor::of(83.4))],
    'an empty reason' => [Entry::of(Path::of('app/Http'), Floor::of(83.4))->lowered(Lowered::from(Floor::of(83.41), ''))],
]);

it('fails a lowered that names another floor than the base\'s', function () use ($base, $trees): void {
    $head = $base->with(Entry::of(Path::of('app/Http'), Floor::of(80))->lowered(Lowered::from(Floor::of(90), 'The export went.')));

    expect(Lowering::against($base, $head, $trees))->toEqual(Failures::of(Failure::that(
        "The floor of app/Http went down from 83.41, and its \"lowered\" says it came from 90.\n"
        . 'Write "from": 83.41, the floor the base branch holds.',
    )));
});

it('fails a tree that left the baseline while it is still a tree, and lets a tree whose path is gone leave', function () use ($base, $trees): void {
    $head = Baseline::of(Entry::of(Path::of('app/Domain'), Floor::of(100)));
    $gone = Trees::of(Tree::at(Path::of('app/Domain'), Undeclared::floor(), Package::at(Path::root())));

    expect(Lowering::against($base, $head, $trees))->toEqual(Failures::of(Failure::that(
        'app/Http left the baseline, and it is still a tree. A tree leaves the baseline only when its path is gone.',
    )))->and(Lowering::against($base, $head, $gone))->toEqual(Failures::none());
});

it('names every failure, in the base\'s order', function () use ($base, $trees): void {
    expect(Lowering::against($base, Baseline::none(), $trees))->toHaveCount(2);
});

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

$makeBase = static fn(): Baseline => Baseline::of(Entry::of(Path::of('app/Http'), Floor::of(83.41)), Entry::of(Path::of('app/Domain'), Floor::of(100)));
$makeTrees = static fn(): Trees => Trees::of(
    Tree::at(Path::of('app/Http'), Undeclared::floor(), Package::at(Path::root())),
    Tree::at(Path::of('app/Domain'), Undeclared::floor(), Package::at(Path::root())),
);

it('passes floors that held or rose, and a floor lowered from the base\'s with a reason', function (Baseline $head) use ($makeBase, $makeTrees): void {
    $base = $makeBase();
    $trees = $makeTrees();

    expect(Lowering::against($base, $head, $trees))->toEqual(Failures::none());
})->with([
    'unchanged' => [fn(): Baseline => $makeBase()],
    'raised' => [fn(): Baseline => $makeBase()->with(Entry::of(Path::of('app/Http'), Floor::of(90)))],
    'a tree added' => [fn(): Baseline => $makeBase()->with(Entry::of(Path::of('app/New'), Floor::of(10)))],
    'lowered with a reason' => [fn(): Baseline => $makeBase()->with(
        Entry::of(Path::of('app/Http'), Floor::of(80))->lowered(Lowered::from(Floor::of(83.41), 'The export went.')),
    )],
]);

it('fails a floor lowered with no reason', function (Entry $entry) use ($makeBase, $makeTrees): void {
    $base = $makeBase();
    $trees = $makeTrees();

    expect(Lowering::against($base, $base->with($entry), $trees))->toEqual(Failures::of(Failure::that(
        "The floor of app/Http went down from 83.41 to 83.4 with no reason. A floor goes down only on purpose:\n"
        . 'add "lowered": { "from": 83.41, "reason": "…" } to its entry in the baseline.',
    )));
})->with([
    'no lowered' => [fn(): Entry => Entry::of(Path::of('app/Http'), Floor::of(83.4))],
    'an empty reason' => [fn(): Entry => Entry::of(Path::of('app/Http'), Floor::of(83.4))->lowered(Lowered::from(Floor::of(83.41), ''))],
]);

it('fails a lowered that names another floor than the base\'s', function () use ($makeBase, $makeTrees): void {
    $base = $makeBase();
    $trees = $makeTrees();

    $head = $base->with(Entry::of(Path::of('app/Http'), Floor::of(80))->lowered(Lowered::from(Floor::of(90), 'The export went.')));

    expect(Lowering::against($base, $head, $trees))->toEqual(Failures::of(Failure::that(
        "The floor of app/Http went down from 83.41, and its \"lowered\" says it came from 90.\n"
        . 'Write "from": 83.41, the floor the base branch holds.',
    )));
});

it('fails a tree that left the baseline while it is still a tree, and lets a tree whose path is gone leave', function () use ($makeBase, $makeTrees): void {
    $base = $makeBase();
    $trees = $makeTrees();

    $head = Baseline::of(Entry::of(Path::of('app/Domain'), Floor::of(100)));
    $gone = Trees::of(Tree::at(Path::of('app/Domain'), Undeclared::floor(), Package::at(Path::root())));

    expect(Lowering::against($base, $head, $trees))->toEqual(Failures::of(Failure::that(
        'app/Http left the baseline, and it is still a tree. A tree leaves the baseline only when its path is gone.',
    )))->and(Lowering::against($base, $head, $gone))->toEqual(Failures::none());
});

it('names every failure, in the base\'s order', function () use ($makeBase, $makeTrees): void {
    $base = $makeBase();
    $trees = $makeTrees();

    expect(Lowering::against($base, Baseline::none(), $trees))->toHaveCount(2);
});

it('holds each package\'s security floor to the same rules, and lets a set leave only with its package', function () use ($makeTrees): void {
    $trees = $makeTrees();

    $base = Baseline::none()->withSecurity(Entry::of(Path::root(), Floor::of(97.5)), Entry::of(Path::of('packages/gone'), Floor::of(80)));
    $lowered = Entry::of(Path::root(), Floor::of(95))->lowered(Lowered::from(Floor::of(97.5), 'The legacy login left with its tests.'));

    expect(Lowering::against($base, Baseline::none()->withSecurity(Entry::of(Path::root(), Floor::of(98))), $trees))->toEqual(Failures::none())
        ->and(Lowering::against($base, Baseline::none()->withSecurity($lowered), $trees))->toEqual(Failures::none())
        ->and(Lowering::against($base, Baseline::none()->withSecurity(Entry::of(Path::root(), Floor::of(95))), $trees))
        ->toEqual(Failures::of(Failure::that(
            "The floor of the security set of . went down from 97.5 to 95 with no reason. A floor goes down only on purpose:\n"
            . 'add "lowered": { "from": 97.5, "reason": "…" } to its entry in the baseline.',
        )))
        ->and(Lowering::against($base, Baseline::none()->withSecurity(
            Entry::of(Path::root(), Floor::of(95))->lowered(Lowered::from(Floor::of(96), 'Why.')),
        ), $trees))
        ->toEqual(Failures::of(Failure::that(
            "The floor of the security set of . went down from 97.5, and its \"lowered\" says it came from 96.\n"
            . 'Write "from": 97.5, the floor the base branch holds.',
        )))
        ->and(Lowering::against($base, Baseline::none(), $trees))->toEqual(Failures::of(Failure::that(
            "The security set of . left the baseline, and it is still a package.\n"
            . 'A security set leaves the baseline only when its package is gone.',
        )));
});

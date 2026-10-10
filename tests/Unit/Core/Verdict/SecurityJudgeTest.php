<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\SecurityJudge;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Secured;

$makeSecurity = static fn(): NamedMutators => NamedMutators::of(Secured::MUTATOR, 'default/UnwrapStripTags');
$makeRoot = static fn(): Package => Package::at(Path::root());
$makeBilling = static fn(): Package => Package::at(Path::of('packages/billing'));

/** @return list<string> each set's package, floor and native ids, in order */
$described = static fn(SecurityVerdicts $sets): array => array_map(
    static fn(SecurityVerdict $set): string => sprintf(
        '%s %s %s',
        $set->package()->path()->value(),
        $set->floor() instanceof Floor ? $set->floor()->hundredths() : 'none',
        implode(',', Judged::natives($set->mutants())),
    ),
    [...$sets],
);

it('judges each package\'s security set over the mutants of its trees the security mutators made', function () use ($makeSecurity, $makeRoot, $makeBilling, $described): void {
    $security = $makeSecurity();
    $root = $makeRoot();
    $billing = $makeBilling();

    $trees = TreeVerdicts::of(
        Secured::tree('app', $root, Undeclared::floor(), Secured::mutant(MutantJudgement::Killed, 1), Secured::mutant(MutantJudgement::Survived, 2, 'Plus')),
        Secured::tree('lib', $root, Undeclared::floor(), Secured::mutant(MutantJudgement::Survived, 3, 'default/UnwrapStripTags')),
        Secured::tree('packages/billing/src', $billing->withSecurityFloor(Floor::of(80)), Undeclared::floor(), Secured::mutant(MutantJudgement::Killed, 4)),
    );
    $sets = SecurityJudge::of(Baseline::none(), Uncovered::Count, Floor::of(90))->judged($trees, $security);

    expect($described($sets))->toBe(['. 9000 src/Auth.php:1,src/Auth.php:3', 'packages/billing 8000 src/Auth.php:4'])
        ->and(array_map(static fn(SecurityVerdict $set): Judgement => $set->judgement(), [...$sets]))
        ->toBe([Judgement::Failed, Judgement::Passed]);
});

it('takes the floor a package declares from whichever of its trees carries it', function () use ($makeSecurity, $makeBilling, $described): void {
    $security = $makeSecurity();
    $billing = $makeBilling();

    $trees = TreeVerdicts::of(
        Secured::tree('packages/billing/src', $billing, Undeclared::floor(), Secured::mutant(MutantJudgement::Killed, 1)),
        Secured::tree('packages/billing/lib', $billing->withSecurityFloor(Floor::of(70)), Undeclared::floor(), Secured::mutant(MutantJudgement::Killed, 2)),
    );

    expect($described(SecurityJudge::of(Baseline::none(), Uncovered::Count, Undeclared::floor())->judged($trees, $security)))
        ->toBe(['packages/billing 7000 src/Auth.php:1,src/Auth.php:2']);
});

it('holds a set to its baseline entry, with the reason it was lowered, and judges a package the baseline holds though it has no security mutant', function () use ($makeSecurity, $makeRoot, $makeBilling): void {
    $security = $makeSecurity();
    $root = $makeRoot();
    $billing = $makeBilling();

    $lowered = Lowered::from(Floor::of(90), 'The legacy login left with its tests.');
    $baseline = Baseline::none()->withSecurity(
        Entry::of(Path::root(), Floor::of(85))->lowered($lowered),
        Entry::of(Path::of('packages/billing'), Floor::of(95)),
    );
    $trees = TreeVerdicts::of(
        Secured::tree('app', $root, Undeclared::floor(), Secured::mutant(MutantJudgement::Killed, 1)),
        Secured::tree('packages/billing/src', $billing, Undeclared::floor(), Secured::mutant(MutantJudgement::Killed, 2, 'Plus')),
        Secured::tree('packages/clock/src', Package::at(Path::of('packages/clock')), Undeclared::floor(), Secured::mutant(MutantJudgement::Killed, 3, 'Plus')),
    );
    $sets = [...SecurityJudge::of($baseline, Uncovered::Count, Undeclared::floor())->judged($trees, $security)];

    expect(count($sets))->toBe(2)
        ->and($sets[0]->baseline())->toEqual(Floor::of(85))
        ->and($sets[0]->lowering())->toBe($lowered)
        ->and($sets[1]->package()->path())->toEqual(Path::of('packages/billing'))
        ->and($sets[1]->lowering())->toEqual(Unlowered::floor())
        ->and($sets[1]->judgement())->toBe(Judgement::NothingToMutate);
});

it('judges one empty set at the root where no tree holds a security mutant, and none where no mutator makes one', function () use ($makeSecurity, $makeRoot): void {
    $security = $makeSecurity();
    $root = $makeRoot();

    $trees = TreeVerdicts::of(Secured::tree('app', $root, Undeclared::floor(), Secured::mutant(MutantJudgement::Survived, 1, 'Plus')));
    $judge = SecurityJudge::of(Baseline::none(), Uncovered::Count, Floor::of(90));
    $empty = [...$judge->judged($trees, $security)];

    expect(count($empty))->toBe(1)
        ->and($empty[0]->package()->path())->toEqual(Path::root())
        ->and($empty[0]->baseline())->toEqual(Unrecorded::floor())
        ->and($empty[0]->judgement())->toBe(Judgement::NothingToMutate)
        ->and($judge->judged(TreeVerdicts::none(), $security))->toHaveCount(1)
        ->and($judge->judged($trees, NamedMutators::of()))->toEqual(SecurityVerdicts::none());
});

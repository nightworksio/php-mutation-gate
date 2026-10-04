<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unraised;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Secured;

it('scores its mutants against the higher of its declared floor and its baseline\'s', function (): void {
    $killed = Secured::mutant(MutantJudgement::Killed, 1);
    $survived = Secured::mutant(MutantJudgement::Survived, 2);
    $set = Secured::set('packages/auth', Floor::of(40), Floor::of(60), $killed, $survived);

    expect($set->package()->path())->toEqual(Path::of('packages/auth'))
        ->and($set->declared())->toEqual(Floor::of(40))
        ->and($set->baseline())->toEqual(Floor::of(60))
        ->and($set->floor())->toEqual(Floor::of(60))
        ->and($set->score())->toEqual(Score::ofHundredths(5000))
        ->and($set->judgement())->toBe(Judgement::Failed)
        ->and($set->counts()->number(MutantJudgement::Survived))->toBe(1)
        ->and($set->mutants())->toHaveCount(2)
        ->and(Judged::natives($set->survivors()))->toBe(['src/Auth.php:2'])
        ->and(Secured::set('.', Floor::of(50), Unrecorded::floor(), $killed, $survived)->judgement())->toBe(Judgement::Passed);
});

it('holds a set with no floor anywhere to none, and raises the baseline to its score', function (): void {
    $set = Secured::set('.', Undeclared::floor(), Unrecorded::floor(), Secured::mutant(MutantJudgement::Killed));

    expect($set->floor())->toEqual(Undeclared::floor())
        ->and($set->judgement())->toBe(Judgement::Passed)
        ->and($set->raised())->toEqual(Floor::of(100))
        ->and(Secured::set('.', Floor::of(100), Unrecorded::floor(), Secured::mutant(MutantJudgement::Killed))->raised())
        ->toEqual(Unraised::floor());
});

it('has nothing to mutate without a security mutant, and raises nothing', function (): void {
    $set = Secured::set('.', Floor::of(90), Unrecorded::floor());

    expect($set->score())->toEqual(NothingToMutate::found())
        ->and($set->judgement())->toBe(Judgement::NothingToMutate)
        ->and($set->raised())->toEqual(Unraised::floor());
});

it('carries why the baseline lowered its floor', function (): void {
    $lowered = Lowered::from(Floor::of(90), 'The legacy login left with its tests.');
    $set = Secured::set('.', Undeclared::floor(), Floor::of(80));

    expect($set->lowering())->toEqual(Unlowered::floor())
        ->and($set->withLowering($lowered)->lowering())->toBe($lowered);
});

it('holds an exempt set to no floor and raises none, keeping its declared floor and baseline', function (): void {
    $set = Secured::set('.', Floor::of(90), Floor::of(95), Secured::mutant(MutantJudgement::Survived))
        ->exempting(Exempt::because('--suite judges no floor'));
    $unfloored = Secured::set('.', Undeclared::floor(), Unrecorded::floor(), Secured::mutant(MutantJudgement::Killed))
        ->exempting(Exempt::because('--suite judges no floor'));

    expect($set->floor())->toEqual(Exempt::because('--suite judges no floor'))
        ->and($set->judgement())->toBe(Judgement::Exempt)
        ->and($set->raised())->toEqual(Unraised::floor())
        ->and($set->declared())->toEqual(Floor::of(90))
        ->and($set->baseline())->toEqual(Floor::of(95))
        ->and($unfloored->floor())->toEqual(Exempt::because('--suite judges no floor'))
        ->and($unfloored->raised())->toEqual(Unraised::floor());
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\OwnerVerdict;
use NightWorksIO\MutationGate\Core\Verdict\OwnerVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Owners;

/**
 * @param  iterable<OwnerVerdict> $groups
 * @return list<string>
 */
function ownerGroupNames(iterable $groups): array
{
    $names = [];

    foreach ($groups as $group) {
        $names[] = $group->group()->name();
    }

    return $names;
}

it('groups every mutant across the trees by the owners of its file, by name and the unowned last', function (): void {
    $groups = Owners::verdicts();

    expect(ownerGroupNames($groups))->toBe(['@acme/payments @jo', '@acme/web', 'unowned'])
        ->and($groups)->toHaveCount(3)
        ->and($groups->areGrouped())->toBeTrue();
});

it('puts each mutant in one group, so the groups add up to the project, kills a ledger proved among them', function (): void {
    $total = 0;

    foreach (Owners::verdicts() as $group) {
        $total += count($group->mutants());
    }

    $unowned = [...Owners::verdicts()][2];

    expect($total)->toBe(count(Owners::trees()->mutants()))
        ->and($unowned->counts()->number(MutantJudgement::Killed))->toBe(1)
        ->and($unowned->counts()->number(MutantJudgement::Survived))->toBe(1);
});

it('scores each group over its own mutants, counting uncovered ones as it was told', function (Uncovered $uncovered, int $hundredths): void {
    $billing = [...Owners::verdicts($uncovered)][0];

    expect($billing->score())->toEqual(Score::ofHundredths($hundredths))
        ->and($billing->judgement())->toBe(Judgement::Passed);
})->with([
    'uncovered counted' => [Uncovered::Count, 3_333],
    'uncovered left out' => [Uncovered::Exclude, 5_000],
]);

it('knows which groups the change touched', function (): void {
    expect(ownerGroupNames(Owners::verdicts()->touched()))->toBe(['@acme/payments @jo']);
});

it('knows the group of each file of the mutants, and of no other', function (): void {
    expect(Owners::verdicts()->groupOf(Path::of('src/Http/Route.php'))->name())->toBe('@acme/web')
        ->and(Owners::verdicts()->groupOf(Path::of('src/Billing/Pay.php'))->name())->toBe('@acme/payments @jo')
        ->and(Owners::verdicts()->groupOf(Path::of('src/Http/Kernel.php'))->isUnowned())->toBeTrue()
        ->and(Owners::verdicts()->touched()->groupOf(Path::of('src/Http/Route.php'))->name())->toBe('@acme/web')
        ->and(Owners::verdicts()->touched()->areGrouped())->toBeTrue()
        ->and(Owners::verdicts()->touched())->toHaveCount(1);
});

it('groups a file whose mutants are all kills a ledger proved, keeping each mutant in the order the trees hold it', function (): void {
    $owners = CodeOwners::read(Path::of('CODEOWNERS'), "/src/Money.php @money\n/src/Billing/ @acme/payments\n");
    $groups = [...OwnerVerdicts::of(Owners::trees(), $owners, Uncovered::Count)];
    $natives = static fn(int $at): array => array_map(
        static fn(JudgedMutant|JudgedKill $mutant): string => $mutant->mutant()->location()->file()->value(),
        [...$groups[$at]->mutants()],
    );

    expect(ownerGroupNames($groups))->toBe(['@acme/payments', '@money', 'unowned'])
        ->and($natives(0))->toBe(['src/Billing/Pay.php', 'src/Billing/Pay.php', 'src/Billing/Pay.php'])
        ->and($natives(1))->toBe(['src/Money.php'])
        ->and($groups[1]->counts()->number(MutantJudgement::Killed))->toBe(1)
        ->and($natives(2))->toBe(['src/Http/Route.php', 'lib/Clock.php']);
});

it('groups nothing where the run read no CODEOWNERS', function (): void {
    $none = OwnerVerdicts::of(Owners::trees(), CodeOwners::none(), Uncovered::Count);

    expect($none)->toHaveCount(0)
        ->and($none->areGrouped())->toBeFalse()
        ->and(OwnerVerdicts::none()->areGrouped())->toBeFalse()
        ->and(OwnerVerdicts::none())->toHaveCount(0);
});

it('has no group for a project with no mutant, though it read a CODEOWNERS', function (): void {
    $empty = OwnerVerdicts::of(TreeVerdicts::none(), Owners::codeOwners(), Uncovered::Count);

    expect($empty)->toHaveCount(0)
        ->and($empty->areGrouped())->toBeTrue();
});

it('orders the groups by name, whatever order their files are met in', function (): void {
    $owners = CodeOwners::read(Path::of('CODEOWNERS'), "/src/Billing/ @zz\n/src/Http/ @aa\n");

    expect(ownerGroupNames(OwnerVerdicts::of(Owners::trees(), $owners, Uncovered::Count)))->toBe(['@aa', '@zz', 'unowned']);
});

it('says a change touched a group with one mutant on a changed line', function (): void {
    $owners = CodeOwners::read(Path::of('CODEOWNERS'), "/src/Billing/Pay.php @pay\n");
    $touched = OwnerVerdicts::of(Owners::trees(), $owners, Uncovered::Count)->touched();
    $only = TreeVerdicts::of(TreeVerdict::judged(
        Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::of(
            Owners::changed(Judged::mutant('one', MutantJudgement::Survived, 'src/Billing/Pay.php', 2)),
            Judged::mutant('two', MutantJudgement::Survived, 'src/Billing/Pay.php', 3),
        ),
        Uncovered::Count,
    ));

    expect(ownerGroupNames($touched))->toBe(['@pay'])
        ->and(ownerGroupNames(OwnerVerdicts::of($only, $owners, Uncovered::Count)->touched()))->toBe(['@pay']);
});

it('orders a group named before the unowned one first whichever it meets first', function (): void {
    $owners = CodeOwners::read(Path::of('CODEOWNERS'), "/lib/ @zed\n");
    $groups = OwnerVerdicts::of(Owners::trees(), $owners, Uncovered::Count);

    expect(ownerGroupNames($groups))->toBe(['@zed', 'unowned']);
});

it('holds a group, its mutants and the score they add up to, and passes with no floor', function (): void {
    $billing = [...Owners::verdicts()][0];

    expect($billing)->toBeInstanceOf(OwnerVerdict::class)
        ->and($billing->group()->name())->toBe('@acme/payments @jo')
        ->and(count($billing->mutants()))->toBe(3)
        ->and($billing->isTouched())->toBeTrue()
        ->and([...Owners::verdicts()][1]->isTouched())->toBeFalse();
});

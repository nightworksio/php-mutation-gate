<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;
use NightWorksIO\MutationGate\Core\Owner\OwnerFloors;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\OwnerFloorVerdict;
use NightWorksIO\MutationGate\Core\Verdict\OwnerFloorVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Owners;

/** The fixture's owners, with `@jo` named by the billing rule and the web rule both. */
function ownerFloorsOwners(): CodeOwners
{
    return CodeOwners::read(Path::of('.github/CODEOWNERS'), "/src/Billing/ @acme/payments @jo\n/src/Http/ @acme/web @jo\n");
}

/**
 * @param  array<string, int|float> $floors
 * @return list<OwnerFloorVerdict>
 */
function ownerFloorsOf(array $floors, CodeOwners $owners, Uncovered $uncovered = Uncovered::Count): array
{
    return [...OwnerFloorVerdicts::of(Owners::trees(), $owners, $uncovered, Owners::floorsOf($floors))];
}

it('judges an owner over every file a rule names it in, whatever the rule\'s group', function (): void {
    [$jo] = ownerFloorsOf(['@jo' => 60], ownerFloorsOwners());

    expect($jo->owner()->value())->toBe('@jo')
        ->and($jo->floor())->toEqual(Floor::of(60))
        ->and(count($jo->mutants()))->toBe(4)
        ->and($jo->counts()->number(MutantJudgement::Killed))->toBe(2)
        ->and($jo->counts()->number(MutantJudgement::Survived))->toBe(1)
        ->and($jo->survivors()->count())->toBe(2)
        ->and($jo->uncovered())->toBe(Uncovered::Count)
        ->and($jo->score())->toEqual(Score::of(2, 4))
        ->and($jo->judgement())->toBe(Judgement::Failed);
});

it('passes an owner whose score meets its floor, and counts uncovered mutants as the config says', function (): void {
    [$counted] = ownerFloorsOf(['@acme/web' => 100], ownerFloorsOwners());
    [$excluded] = ownerFloorsOf(['@acme/payments' => 50], ownerFloorsOwners(), Uncovered::Exclude);

    expect([$counted->judgement(), $excluded->score(), $excluded->judgement()])
        ->toEqual([Judgement::Passed, Score::of(1, 2), Judgement::Passed]);
});

it('matches an owner in any case, as GitHub does, and has nothing to mutate for one no rule names', function (): void {
    [$cased, $nobody] = ownerFloorsOf(['@ACME/Web' => 90, '@nobody' => 90], ownerFloorsOwners());

    expect(count($cased->mutants()))->toBe(1)
        ->and($nobody->score())->toBeInstanceOf(NothingToMutate::class)
        ->and($nobody->judgement())->toBe(Judgement::NothingToMutate);
});

it('lists the owners with a floor by name, and none where no owner has one', function (): void {
    $floored = ownerFloorsOf(['@jo' => 10, '@acme/web' => 10], ownerFloorsOwners());

    expect(array_map(static fn(OwnerFloorVerdict $owner): string => $owner->owner()->value(), $floored))
        ->toBe(['@acme/web', '@jo'])
        ->and(OwnerFloorVerdicts::of(Owners::trees(), ownerFloorsOwners(), Uncovered::Count, OwnerFloors::none()))->toHaveCount(0)
        ->and(OwnerFloorVerdicts::none())->toHaveCount(0);
});

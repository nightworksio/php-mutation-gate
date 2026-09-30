<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Judged;

$mutant = static fn(string $file, int $line, MutantStatus $status): Mutant => Mutant::of(
    MutantId::hash(Path::of($file), 'LessThan', sprintf('%d', $line), 0),
    sprintf('%s:%d', $file, $line),
    Location::of(Path::of($file), Line::of($line), Line::of($line)),
    Mutation::of('LessThan', MutatorFamily::Boundary, ''),
    $status,
    Unmeasured::duration(),
);
$root = Package::at(Path::root());
$app = Tree::at(Path::of('app'), Floor::of(50), $root);
$http = Tree::at(Path::of('app/Http'), Undeclared::floor(), $root);
$billing = Tree::at(Path::of('packages/billing/src'), Floor::of(100), Package::at(Path::of('packages/billing')))
    ->withNewCodeFloor(Floor::of(80));
$trees = Trees::of($app, $http, $billing);
$reach = Reach::nothing(Packages::of($trees))
    ->withLines(Path::of('app/Http/Controller.php'), Lines::of(Line::of(3)))
    ->withLines(Path::of('packages/billing/src/Invoice.php'), Lines::of(Line::of(9)));
$results = UnitResults::of(
    UnitResult::of(Unit::file(Path::of('app/Kernel.php')), Origin::Proved, Mutants::of(
        $mutant('app/Kernel.php', 1, MutantStatus::Killed),
        $mutant('app/Kernel.php', 2, MutantStatus::Survived),
    )),
    UnitResult::of(Unit::file(Path::of('app/Http/Controller.php')), Origin::Run, Mutants::of(
        $mutant('app/Http/Controller.php', 3, MutantStatus::Survived),
        $mutant('app/Http/Controller.php', 4, MutantStatus::TimedOut),
    )),
    UnitResult::of(Unit::held(Path::of('app/Http/Middleware'), Group::named('holds:app/Http/Middleware')), Origin::Carried, Mutants::of(
        $mutant('app/Http/Middleware/Auth.php', 5, MutantStatus::Uncovered),
    )),
    UnitResult::of(Unit::file(Path::of('lib/Loose.php')), Origin::Run, Mutants::of(
        $mutant('lib/Loose.php', 1, MutantStatus::Survived),
    )),
);
$judge = Judge::of($trees, Baseline::of(Entry::of(Path::of('app/Http'), Floor::of(40))), $reach, Uncovered::Count);

it('judges each tree over the units it holds most closely, with their origins', function () use ($judge, $results): void {
    $verdicts = [...$judge->trees($results)];
    $units = static fn(TreeVerdict $verdict): array => array_map(
        static fn(JudgedUnit $unit): string => sprintf('%s %s', $unit->unit()->path()->value(), $unit->origin()->value),
        [...$verdict->units()],
    );

    expect(array_map(static fn(TreeVerdict $verdict): string => $verdict->tree()->path()->value(), $verdicts))
        ->toBe(['app', 'app/Http', 'packages/billing/src'])
        ->and($units($verdicts[0]))->toBe(['app/Kernel.php proved'])
        ->and($units($verdicts[1]))->toBe(['app/Http/Controller.php run', 'app/Http/Middleware carried'])
        ->and($units($verdicts[2]))->toBe([]);
});

it('judges each mutant as reported, marks those on changed lines, and scores the tree against its floor', function () use ($judge, $results): void {
    [$app, $http, $billing] = [...$judge->trees($results)];

    expect($app->score())->toEqual(Score::ofHundredths(5_000))
        ->and($app->judgement())->toBe(Judgement::Passed)
        ->and($app->baseline())->toEqual(Unrecorded::floor())
        ->and($http->baseline())->toEqual(Floor::of(40))
        ->and($http->counts()->number(MutantJudgement::KilledByTimeout))->toBe(1)
        ->and($http->counts()->number(MutantJudgement::Uncovered))->toBe(1)
        ->and($http->score())->toEqual(Score::ofHundredths(3_333))
        ->and($http->judgement())->toBe(Judgement::Failed)
        ->and(Judged::natives($http->survivors()))->toBe(['app/Http/Controller.php:3', 'app/Http/Middleware/Auth.php:5'])
        ->and([...$http->mutants()][0]->isOnChangedLine())->toBeTrue()
        ->and([...$http->mutants()][1]->isOnChangedLine())->toBeFalse()
        ->and($billing->score())->toEqual(NothingToMutate::found());
});

it('holds the mutants on changed lines to the floor for new code, per package and floor', function () use ($trees, $reach, $results, $mutant): void {
    $judge = Judge::of($trees, Baseline::none(), $reach, Uncovered::Count);
    $billing = UnitResult::of(Unit::file(Path::of('packages/billing/src/Invoice.php')), Origin::Run, Mutants::of(
        $mutant('packages/billing/src/Invoice.php', 9, MutantStatus::Killed),
    ));
    $sets = [...$judge->newCode($judge->trees($results->with($billing)), Floor::of(100))];

    expect(array_map(static fn(NewCodeVerdict $set): string => sprintf(
        '%s %d %s',
        $set->package()->path()->value(),
        $set->floor()->hundredths(),
        $set->judgement()->value,
    ), $sets))->toBe(['. 10000 failed', 'packages/billing 8000 passed'])
        ->and(Judged::natives($sets[0]->mutants()))->toBe(['app/Http/Controller.php:3']);
});

it('judges one empty new-code set, which passes and says so, when no changed line holds a mutant', function () use ($trees): void {
    $judge = Judge::of($trees, Baseline::none(), Reach::nothing(Packages::of($trees)), Uncovered::Count);
    $sets = [...$judge->newCode($judge->trees(UnitResults::none()), Floor::of(90))];

    expect($sets)->toHaveCount(1)
        ->and($sets[0]->package()->path())->toEqual(Path::root())
        ->and($sets[0]->floor())->toEqual(Floor::of(90))
        ->and($sets[0]->judgement())->toBe(Judgement::NothingToMutate);
});

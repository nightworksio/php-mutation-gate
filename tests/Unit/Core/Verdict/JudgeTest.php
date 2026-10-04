<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\IgnoredMutant;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\NamedMutators;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\Judge;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Moment;

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
        $mutant('app/Http/Controller.php', 4, MutantStatus::TimedOut)
            ->withLimit(Seconds::of(10.0))
            ->withUnmutatedNeed(Seconds::of(1.0)),
    )),
    UnitResult::of(Unit::held(Path::of('app/Http/Middleware'), Group::named('holds:app/Http/Middleware')), Origin::Carried, Mutants::of(
        $mutant('app/Http/Middleware/Auth.php', 5, MutantStatus::Uncovered),
    )),
    UnitResult::of(Unit::file(Path::of('lib/Loose.php')), Origin::Run, Mutants::of(
        $mutant('lib/Loose.php', 1, MutantStatus::Survived),
    )),
);
$judge = Judge::of(
    $trees,
    Baseline::of(Entry::of(Path::of('app/Http'), Floor::of(40))),
    $reach,
    Uncovered::Count,
    TimeoutMode::Confirm,
    Ignoring::none(),
);

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

it('carries the reason a baseline gives for lowering a tree\'s floor', function () use ($trees, $reach, $results): void {
    $lowered = Lowered::from(Floor::of(60), 'The HTTP layer moved to integration tests');
    $baseline = Baseline::of(Entry::of(Path::of('app/Http'), Floor::of(40))->lowered($lowered), Entry::of(Path::of('app'), Floor::of(10)));
    $judge = Judge::of($trees, $baseline, $reach, Uncovered::Count, TimeoutMode::Confirm, Ignoring::none());
    [$app, $http] = [...$judge->trees($results)];

    expect($http->lowering())->toBe($lowered)
        ->and($app->lowering())->toEqual(Unlowered::floor());
});

it('leaves a survivor the config ignores out of its tree\'s score, with the ignore\'s reason', function () use ($trees, $reach, $results, $mutant): void {
    $ignoring = Ignoring::of(
        Listed::of(IgnoredMutant::of($mutant('app/Kernel.php', 2, MutantStatus::Survived)->id(), 'Both branches build the same list', Absent::setting())),
        new DateTimeImmutable(Configs::NOW),
    );
    [$app] = [...Judge::of($trees, Baseline::none(), $reach, Uncovered::Count, TimeoutMode::Confirm, $ignoring)->trees($results)];
    $reason = [...$app->mutants()][1]->mutant()->reason();

    expect($app->score())->toEqual(Score::ofHundredths(10_000))
        ->and($app->counts()->number(MutantJudgement::Ignored))->toBe(1)
        ->and(Judged::natives($app->survivors()))->toBe([])
        ->and($reason instanceof Reason ? $reason->text() : '')->toBe('Both branches build the same list');
});

it('holds the mutants on changed lines to the floor for new code, per package and floor', function () use ($trees, $reach, $results, $mutant): void {
    $judge = Judge::of($trees, Baseline::none(), $reach, Uncovered::Count, TimeoutMode::Confirm, Ignoring::none());
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
    $judge = Judge::of(
        $trees,
        Baseline::none(),
        Reach::nothing(Packages::of($trees)),
        Uncovered::Count,
        TimeoutMode::Confirm,
        Ignoring::none(),
    );
    $sets = [...$judge->newCode($judge->trees(UnitResults::none()), Floor::of(90))];

    expect($sets)->toHaveCount(1)
        ->and($sets[0]->package()->path())->toEqual(Path::root())
        ->and($sets[0]->floor())->toEqual(Floor::of(90))
        ->and($sets[0]->judgement())->toBe(Judgement::NothingToMutate);
});

it('judges each package\'s security set over the mutants its mutators made, against its baseline entry', function () use ($trees, $reach, $results): void {
    $judge = Judge::of(
        $trees,
        Baseline::none()->withSecurity(Entry::of(Path::root(), Floor::of(25))),
        $reach,
        Uncovered::Count,
        TimeoutMode::Confirm,
        Ignoring::none(),
    );
    $sets = [...$judge->security($judge->trees($results), NamedMutators::of('LessThan'), Floor::of(20))];

    expect($sets)->toHaveCount(1)
        ->and($sets[0]->package()->path())->toEqual(Path::root())
        ->and($sets[0]->declared())->toEqual(Floor::of(20))
        ->and($sets[0]->floor())->toEqual(Floor::of(25))
        ->and($sets[0]->mutants())->toHaveCount(5);
});

it('judges a mutant flaky where its unit\'s result names it so, and every other as reported', function () use (
    $trees,
    $reach,
): void {
    $apart = static fn(int $occurrence, MutantStatus $status): Mutant => Mutant::of(
        MutantId::hash(Path::of('app/Kernel.php'), 'LessThan', '', $occurrence),
        sprintf('app/Kernel.php:%d', $occurrence),
        Location::of(Path::of('app/Kernel.php'), Line::of(1), Line::of(1)),
        Mutation::of('LessThan', MutatorFamily::Boundary, ''),
        $status,
        Unmeasured::duration(),
    );
    $flaky = $apart(0, MutantStatus::Survived);
    $killed = $apart(1, MutantStatus::Killed);
    $survived = $apart(2, MutantStatus::Survived);
    $mutants = Mutants::of($flaky, $killed, $survived);
    $result = UnitResult::of(Unit::file(Path::of('app/Kernel.php')), Origin::Run, $mutants)
        ->withFlaky(MutantIds::of($flaky->id()));
    $judge = Judge::of($trees, Baseline::none(), $reach, Uncovered::Count, TimeoutMode::Confirm, Ignoring::none());
    [$app] = [...$judge->trees(UnitResults::of($result))];

    expect(array_map(static fn(JudgedMutant|JudgedKill $judged): MutantJudgement => $judged->judgement(), [...$app->mutants()]))
        ->toBe([MutantJudgement::Flaky, MutantJudgement::Killed, MutantJudgement::Survived]);
});

it('judges each kill a ledger proved killed beside the mutants it reported in full, marks those on changed lines, and keeps the run the proof names', function () use ($judge, $mutant): void {
    $run = Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base'));
    $kill = static fn(int $line): ProvedKill => ProvedKill::of(
        MutantId::hash(Path::of('packages/billing/src/Invoice.php'), 'LessThan', sprintf('%d', $line), 0),
        Path::of('packages/billing/src/Invoice.php'),
        Line::of($line),
        'LessThan',
        TestIds::none(),
    );
    $results = UnitResults::of(UnitResult::held(
        Unit::file(Path::of('packages/billing/src/Invoice.php')),
        Origin::Proved,
        Mutants::of($mutant('packages/billing/src/Invoice.php', 2, MutantStatus::Survived)),
        ProvedKills::of($kill(9), $kill(10), $kill(11)),
        $run,
    ));
    $billing = [...$judge->trees($results)][2];
    $judged = [...$billing->mutants()];

    expect($billing->score())->toEqual(Score::ofHundredths(7_500))
        ->and($billing->counts()->number(MutantJudgement::Killed))->toBe(3)
        ->and(array_map(static fn(JudgedMutant|JudgedKill $mutant): string => $mutant->mutant()::class, $judged))
        ->toBe([Mutant::class, ProvedKill::class, ProvedKill::class, ProvedKill::class])
        ->and(array_map(static fn(JudgedMutant|JudgedKill $mutant): bool => $mutant->isOnChangedLine(), $judged))->toBe([false, true, false, false])
        ->and([...$results][0]->kills())->toEqual(ProvedKills::of($kill(9), $kill(10), $kill(11)))
        ->and([...$billing->units()][0]->run())->toBe($run);
});

it('judges each mutant by the tests the kill matrix says cover it, a held unit\'s by those of them its holding tests run, and none where it names none', function () use ($judge, $results, $mutant): void {
    $matrix = KillMatrix::of(MatrixKind::FirstKiller, CoverageMap::empty()
        ->covered(Path::of('app/Kernel.php'), Line::of(2), TestId::of('KernelTest::boots'))
        ->covered(Path::of('app/Http/Middleware/Auth.php'), Line::of(5), TestId::of('AuthTest::checks'))
        ->covered(Path::of('app/Http/Middleware/Auth.php'), Line::of(5), TestId::of('KernelTest::boots')));
    $tested = static fn(Judge $judging, UnitResults $judged): array => array_map(
        static fn(JudgedMutant|JudgedKill $one): array => array_map(static fn(TestId $test): string => $test->value(), [...$one->tests()]),
        [...$judging->trees($judged)->mutants()],
    );
    $held = UnitResults::of(UnitResult::of(
        Unit::held(Path::of('app/Http/Middleware'), Group::named('holds:app/Http/Middleware')),
        Origin::Run,
        Mutants::of($mutant('app/Http/Middleware/Auth.php', 5, MutantStatus::Survived)),
    )->judgedBy(TestIds::of(TestId::of('AuthTest::checks'), TestId::of('AuthTest::refuses'))));

    expect($tested($judge->judging($matrix), $results))->toBe([[], ['KernelTest::boots'], [], [], []])
        ->and($tested($judge->judging($matrix), $held))->toBe([['AuthTest::checks']])
        ->and($tested($judge, $results))->toBe([[], [], [], [], []]);
});

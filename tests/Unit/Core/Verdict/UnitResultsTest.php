<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\ThisRun;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Moment;

$result = static fn(string $path): UnitResult => UnitResult::of(Unit::file(Path::of($path)), Origin::Proved, Mutants::none());
$paths = static fn(UnitResults $results): array => array_map(static fn(UnitResult $result): string => $result->unit()->path()->value(), iterator_to_array($results, preserve_keys: true));

it('is a unit, where its result came from and its mutants', function (): void {
    $unit = Unit::file(Path::of('src/Money.php'));
    $mutants = Mutants::none();
    $result = UnitResult::of($unit, Origin::Carried, $mutants);

    expect($result->unit())->toBe($unit)
        ->and($result->origin())->toBe(Origin::Carried)
        ->and($result->mutants())->toBe($mutants)
        ->and($result->flaky())->toHaveCount(0)
        ->and($result->run())->toEqual(ThisRun::result());
});

it('takes a proof\'s mutants, kills, run and judging tests, and keeps them when its flaky mutants are marked', function (): void {
    $run = Run::of('main', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base'));
    $kills = ProvedKills::of(ProvedKill::of(MutantId::hash(Path::of('src/Money.php'), 'Plus', 'diff', 3), Path::of('src/Money.php'), Line::of(3), 'Plus', TestIds::none()));
    $judging = TestIds::of(TestId::of('MoneyTest::adds'));
    $proof = Proof::held(Digest::sha256Of('src/Money.php'), Path::of('src/Money.php'), Mutants::none(), $kills, $run)->judgedBy($judging);
    $result = UnitResult::fromProof(Unit::file(Path::of('src/Money.php')), Origin::Proved, $proof)->withFlaky(MutantIds::none());

    expect([$result->origin(), $result->mutants(), $result->kills(), $result->run(), $result->judging()])
        ->toBe([Origin::Proved, $proof->reported(), $kills, $run, $judging])
        ->and(UnitResult::of(Unit::file(Path::of('src/Money.php')), Origin::Run, Mutants::none())->judging())->toHaveCount(0);
});

it('takes the ids of its flaky mutants, keeping everything else', function (): void {
    $unit = Unit::file(Path::of('src/Money.php'));
    $mutants = Mutants::none();
    $flaky = MutantIds::of(MutantId::hash(Path::of('src/Money.php'), 'Plus', 'diff', 0));
    $result = UnitResult::of($unit, Origin::Run, $mutants)->withFlaky($flaky);

    expect($result->flaky())->toBe($flaky)
        ->and($result->unit())->toBe($unit)
        ->and($result->origin())->toBe(Origin::Run)
        ->and($result->mutants())->toBe($mutants);
});

it('holds nothing to begin with', function (): void {
    expect(UnitResults::none())->toHaveCount(0);
});

it('keeps results in the order they were added, numbered from nought', function () use ($result, $paths): void {
    expect($paths(UnitResults::of(...['b' => $result('b'), 'a' => $result('a')])))->toBe(['b', 'a']);
});

it('adds a result without changing the results it came from', function () use ($result, $paths): void {
    $results = UnitResults::of($result('a'));

    expect($paths($results->with($result('b'))))->toBe(['a', 'b'])
        ->and($results)->toHaveCount(1);
});

it('joins these results and those, these first, without changing either', function () use ($result, $paths): void {
    $these = UnitResults::of($result('a'));
    $those = UnitResults::of($result('b'), $result('c'));

    expect($paths($these->and($those)))->toBe(['a', 'b', 'c'])
        ->and($these)->toHaveCount(1)
        ->and($those)->toHaveCount(2);
});

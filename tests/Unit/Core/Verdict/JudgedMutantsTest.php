<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cluster\Membership;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Clustered;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('holds nothing to begin with', function (): void {
    expect(JudgedMutants::none())->toHaveCount(0);
});

it('keeps mutants in the order they were reported, numbered from nought', function (): void {
    $mutants = JudgedMutants::of(...[
        'b' => Judged::mutant('b', MutantJudgement::Killed),
        'a' => Judged::mutant('a', MutantJudgement::Killed),
    ]);

    expect(array_keys(iterator_to_array($mutants, preserve_keys: true)))->toBe([0, 1])
        ->and(Judged::natives($mutants))->toBe(['b', 'a']);
});

it('adds a mutant, or those of another set, without changing the mutants it came from', function (): void {
    $mutants = JudgedMutants::of(Judged::mutant('a', MutantJudgement::Killed));

    expect(Judged::natives($mutants->with(Judged::mutant('b', MutantJudgement::Killed))))->toBe(['a', 'b'])
        ->and(Judged::natives($mutants->and(Judged::mutants(MutantJudgement::Killed, MutantJudgement::Killed))))
        ->toBe(['a', '0', '1'])
        ->and($mutants)->toHaveCount(1);
});

it('marks every mutant a reach says is on a changed line, and keeps the order', function (): void {
    $reach = Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of('src/Money.php'), Lines::of(Line::of(2)));
    $mutants = JudgedMutants::of(
        Judged::mutant('a', MutantJudgement::Killed, line: 1),
        Judged::mutant('b', MutantJudgement::Killed, line: 2),
    )->within($reach);

    expect(array_map(
        static fn(JudgedMutant|JudgedKill $mutant): bool => $mutant->isOnChangedLine(),
        iterator_to_array($mutants, preserve_keys: true),
    ))->toBe([false, true])
        ->and(array_keys(iterator_to_array($mutants, preserve_keys: true)))->toBe([0, 1]);
});

it('lists the survivors on changed lines first, each part in reported order', function (): void {
    $reach = Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of('src/Money.php'), Lines::of(Line::of(2)));
    $mutants = JudgedMutants::of(
        Judged::mutant('unchanged', MutantJudgement::Survived, line: 1),
        Judged::mutant('killed', MutantJudgement::Killed, line: 2),
        Judged::mutant('changed', MutantJudgement::Flaky, line: 2),
        Judged::mutant('ignored', MutantJudgement::Ignored, line: 2),
        Judged::mutant('uncovered', MutantJudgement::Uncovered, line: 1),
        Judged::mutant('also changed', MutantJudgement::Unjudged, line: 2),
    )->within($reach);

    expect(Judged::natives($mutants->survivors(Uncovered::Count)))->toBe(['changed', 'also changed', 'unchanged', 'uncovered'])
        ->and(Judged::natives($mutants->survivors(Uncovered::Exclude)))->toBe(['changed', 'also changed', 'unchanged'])
        ->and(array_keys(iterator_to_array($mutants->survivors(Uncovered::Count), preserve_keys: true)))->toBe([0, 1, 2, 3]);
});

it('counts its mutants by judgement', function (): void {
    expect(Judged::mutants(MutantJudgement::Flaky, MutantJudgement::Flaky)->counts()->number(MutantJudgement::Flaky))->toBe(2);
});

it('lists the mutants on changed lines, in reported order', function (): void {
    $reach = Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of('src/Money.php'), Lines::of(Line::of(2)));
    $mutants = JudgedMutants::of(
        Judged::mutant('a', MutantJudgement::Killed, line: 2),
        Judged::mutant('b', MutantJudgement::Survived, line: 1),
        Judged::mutant('c', MutantJudgement::Survived, line: 2),
    )->within($reach);

    expect(Judged::natives($mutants->changed()))->toBe(['a', 'c'])
        ->and(array_keys(iterator_to_array($mutants->changed(), preserve_keys: true)))->toBe([0, 1]);
});

it('proves equivalent the survivors among these ids, and leaves every other mutant as it was', function (): void {
    $at = static fn(int $line, MutantJudgement $judgement): JudgedMutant => JudgedMutant::of(
        Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::Arithmetic, Verdicts::diff(sprintf('$a%d + $b;', $line), sprintf('$a%d - $b;', $line))),
        $judgement,
    );
    $mutants = JudgedMutants::of($at(1, MutantJudgement::Survived), $at(2, MutantJudgement::Killed), $at(3, MutantJudgement::Survived));
    $all = iterator_to_array($mutants, preserve_keys: false);
    $proven = $mutants->provenEquivalent(MutantIds::of($all[0]->mutant()->id(), $all[1]->mutant()->id()));

    expect(array_map(static fn(JudgedMutant|JudgedKill $mutant): MutantJudgement => $mutant->judgement(), iterator_to_array($proven, preserve_keys: false)))
        ->toBe([MutantJudgement::Equivalent, MutantJudgement::Killed, MutantJudgement::Survived])
        ->and(Judged::natives($proven))->toBe(['native-1', 'native-2', 'native-3']);
});

it('holds the kills a ledger proved after the mutants reported in full, marks and keeps them, and never lists one as a survivor', function (): void {
    $kill = static fn(int $line): JudgedKill => JudgedKill::of(ProvedKill::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', sprintf('%d', $line), 0),
        Path::of('src/Money.php'),
        Line::of($line),
        'Plus',
        TestIds::none(),
    ));
    $reach = Reach::nothing(Packages::of(Trees::none()))->withLines(Path::of('src/Money.php'), Lines::of(Line::of(2)));
    $mutants = Judged::mutants(MutantJudgement::Survived)
        ->and(JudgedMutants::kills($kill(2)), JudgedMutants::kills($kill(3)))
        ->and(JudgedMutants::kills($kill(4)))
        ->with(Judged::mutant('later', MutantJudgement::Killed))
        ->within($reach);
    $listed = iterator_to_array($mutants, preserve_keys: true);

    expect($mutants)->toHaveCount(5)
        ->and(array_map(static fn(JudgedMutant|JudgedKill $judged): string => $judged::class, $listed))
        ->toBe([JudgedMutant::class, JudgedMutant::class, JudgedKill::class, JudgedKill::class, JudgedKill::class])
        ->and(array_map(static fn(JudgedMutant|JudgedKill $judged): bool => $judged->isOnChangedLine(), $listed))
        ->toBe([false, false, true, false, false])
        ->and($mutants->changed())->toHaveCount(1)
        ->and([...$mutants->changed()][0])->toEqual($kill(2)->within($reach))
        ->and($mutants->counts()->number(MutantJudgement::Killed))->toBe(4)
        ->and(Judged::natives($mutants->survivors(Uncovered::Count)))->toBe(['0'])
        ->and($mutants->provenEquivalent(MutantIds::of($kill(3)->mutant()->id())))->toEqual($mutants);
});

it('marks the survivors of one cause with their cluster, keeping every mutant and its order', function (): void {
    $clustered = Clustered::survivors()->clustered(Uncovered::Count, Clustered::sources());
    $kinds = array_map(
        static fn(JudgedMutant $judged): string => $judged->cluster() instanceof Membership ? $judged->cluster()->kind()->value : 'none',
        Clustered::listed($clustered),
    );

    expect(Judged::natives($clustered))->toBe(Judged::natives(Clustered::survivors()))
        ->and($kinds)->toBe(['expression', 'expression', 'expression', 'none', 'gap', 'gap', 'gap']);
});

it('clusters only survivors and uncovered mutants the score counts', function (MutantJudgement $judgement, Uncovered $uncovered, bool $clustered): void {
    $mutants = [];

    foreach (Clustered::listed(Clustered::removedCalls()) as $call) {
        $mutants[] = JudgedMutant::of($call->mutant(), $judgement)->judgedBy($call->tests());
    }

    $first = Clustered::listed(JudgedMutants::of(...$mutants)->clustered($uncovered, Clustered::sources()))[0];

    expect($first->cluster() instanceof Membership)->toBe($clustered);
})->with([
    'survived' => [MutantJudgement::Survived, Uncovered::Count, true],
    'uncovered, counted' => [MutantJudgement::Uncovered, Uncovered::Count, true],
    'uncovered, left out of the score' => [MutantJudgement::Uncovered, Uncovered::Exclude, false],
    'killed' => [MutantJudgement::Killed, Uncovered::Count, false],
    'flaky' => [MutantJudgement::Flaky, Uncovered::Count, false],
    'unjudged' => [MutantJudgement::Unjudged, Uncovered::Count, false],
    'too slow to judge' => [MutantJudgement::TooSlowToJudge, Uncovered::Count, false],
    'equivalent' => [MutantJudgement::Equivalent, Uncovered::Count, false],
]);

it('clusters nothing without the sources', function (): void {
    foreach (Clustered::listed(Clustered::survivors()->clustered(Uncovered::Count, ByPath::none())) as $judged) {
        expect($judged->cluster())->toEqual(Unclustered::mutant());
    }
});

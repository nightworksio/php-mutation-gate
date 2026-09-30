<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Judged;

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
        static fn(JudgedMutant $mutant): bool => $mutant->isOnChangedLine(),
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

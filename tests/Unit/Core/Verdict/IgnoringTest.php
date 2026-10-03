<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Ignored;
use NightWorksIO\MutationGate\Core\Config\IgnoredMutant;
use NightWorksIO\MutationGate\Core\Config\IgnoredPattern;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Time\Day;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Failures;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Judged;

/** The config's ignores, as the run of {@see Configs::NOW} applies them. */
function ignoring(Ignored ...$entries): Ignoring
{
    return Ignoring::of(Listed::of(...$entries), new DateTimeImmutable(Configs::NOW));
}

/** An ignore of this mutant, with this reason, that expires on this day or never. */
function ignoreOf(JudgedMutant $mutant, string $reason = 'Both branches build the same list', string $expires = ''): IgnoredMutant
{
    $day = Day::of($expires);

    return IgnoredMutant::of($mutant->mutant()->id(), $reason, $day instanceof Day ? $day : Absent::setting());
}

/** One tree's verdict over these mutants. */
function verdictsOver(JudgedMutant ...$mutants): TreeVerdicts
{
    return TreeVerdicts::of(TreeVerdict::judged(
        Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        JudgedMutants::of(...$mutants),
        Uncovered::Count,
    ));
}

/** @return list<string> the text of each failure */
function failureTexts(Failures $failures): array
{
    return array_map(static fn(Failure $failure): string => $failure->text(), iterator_to_array($failures, preserve_keys: false));
}

it('leaves out a survivor and an uncovered mutant an entry names, with its reason', function (MutantJudgement $judgement): void {
    $mutant = Judged::mutant('a', $judgement);
    $judged = ignoring(ignoreOf($mutant, 'Logged elsewhere'))->judged($mutant);
    $reason = $judged->mutant()->reason();

    expect($judged->judgement())->toBe(MutantJudgement::Ignored)
        ->and($reason instanceof Reason ? $reason->text() : '')->toBe('Logged elsewhere');
})->with([MutantJudgement::Survived, MutantJudgement::Uncovered]);

it('leaves a mutant no entry names, and one that does not ask for a test, as judged', function (): void {
    $named = Judged::mutant('a', MutantJudgement::Killed);
    $unnamed = Judged::mutant('a', MutantJudgement::Survived, 'src/Ledger.php');
    $ignoring = ignoring(ignoreOf($named));

    expect($ignoring->judged($named))->toBe($named)
        ->and($ignoring->judged($unnamed))->toBe($unnamed);
});

it('gives the reason of the first entry that names the mutant', function (): void {
    $mutant = Judged::mutant('a', MutantJudgement::Survived);
    $reason = ignoring(
        IgnoredPattern::of(Glob::of('src/**'), 'boundary', 'The bound is never reached', Absent::setting()),
        ignoreOf($mutant, 'Both branches build the same list'),
    )->judged($mutant)->mutant()->reason();

    expect($reason instanceof Reason ? $reason->text() : '')->toBe('The bound is never reached');
});

it('stops applying an entry whose day has passed, and names it with the day', function (): void {
    $mutant = Judged::mutant('a', MutantJudgement::Survived);
    $ignoring = ignoring(ignoreOf($mutant, expires: '2026-09-29'));

    expect($ignoring->judged($mutant)->judgement())->toBe(MutantJudgement::Survived)
        ->and(array_map(static fn(Warning $warning): string => $warning->text(), iterator_to_array($ignoring->warnings(), preserve_keys: false)))
        ->toBe([sprintf('The ignore of %s expired on 2026-09-29, so its mutants count again.', $mutant->mutant()->id()->value())]);
});

it('applies an entry through its last day, and names it in advance from fourteen days before', function (string $expires, bool $named): void {
    $mutant = Judged::mutant('a', MutantJudgement::Survived);
    $ignoring = ignoring(ignoreOf($mutant, expires: $expires));

    expect($ignoring->judged($mutant)->judgement())->toBe(MutantJudgement::Ignored)
        ->and(array_map(static fn(Warning $warning): string => $warning->text(), iterator_to_array($ignoring->warnings(), preserve_keys: false)))
        ->toBe($named ? [sprintf('The ignore of %s expires on %s.', $mutant->mutant()->id()->value(), $expires)] : []);
})->with([
    'its last day is today' => ['2026-09-30', true],
    'its last day is fourteen days away' => ['2026-10-14', true],
    'its last day is fifteen days away' => ['2026-10-15', false],
]);

it('fails on an entry that names no mutant it leaves out', function (MutantJudgement $judgement): void {
    $mutant = Judged::mutant('a', $judgement);
    $stale = IgnoredPattern::of(Glob::of('src/Log/**'), 'MethodCallRemoval', 'Logged elsewhere', Absent::setting());

    expect(failureTexts(ignoring(ignoreOf($mutant), $stale)->stale(verdictsOver($mutant), Failures::none())))->toBe([
        sprintf('The ignore of %s names no mutant it could leave out, in a run that judged every unit: remove it.', $mutant->mutant()->id()->value()),
        'The ignore of MethodCallRemoval in src/Log/** names no mutant it could leave out, in a run that judged every unit: remove it.',
    ]);
})->with([MutantJudgement::Killed, MutantJudgement::Flaky]);

it('fails on an entry that names no mutant it leaves out, beside a survivor it does not name', function (): void {
    $killed = Judged::mutant('a', MutantJudgement::Killed);
    $survivor = Judged::mutant('a', MutantJudgement::Survived, 'src/Ledger.php');

    expect(ignoring(ignoreOf($killed))->stale(verdictsOver($killed, $survivor), Failures::none()))->toHaveCount(1);
});

it('does not fail on an entry that names a mutant it or another entry leaves out, or a proof found equivalent', function (MutantJudgement $judgement): void {
    $mutant = Judged::mutant('a', $judgement);

    expect(ignoring(ignoreOf($mutant))->stale(verdictsOver($mutant), Failures::none()))->toHaveCount(0);
})->with([MutantJudgement::Survived, MutantJudgement::Uncovered, MutantJudgement::Ignored, MutantJudgement::Equivalent]);

it('fails on no entry where a mutant is unjudged, or for one that expired', function (): void {
    $killed = Judged::mutant('a', MutantJudgement::Killed);
    $unjudged = Judged::mutant('a', MutantJudgement::Unjudged, 'src/Ledger.php');

    expect(ignoring(ignoreOf($killed))->stale(verdictsOver($killed, $unjudged), Failures::none()))->toHaveCount(0)
        ->and(ignoring(ignoreOf($killed, expires: '2026-09-29'))->stale(verdictsOver($killed), Failures::none()))->toHaveCount(0);
});

it('fails on no entry where a unit did not run, since it could hold the mutant an entry names', function (): void {
    $killed = Judged::mutant('a', MutantJudgement::Killed);
    $unrun = Failures::of(Failure::that('src/Held.php is unjudged.'));

    expect(ignoring(ignoreOf($killed))->stale(verdictsOver($killed), $unrun))->toHaveCount(0)
        ->and(ignoring(ignoreOf($killed))->stale(verdictsOver($killed), Failures::none()))->toHaveCount(1);
});

it('applies nothing when there are no entries', function (): void {
    $mutant = Judged::mutant('a', MutantJudgement::Survived);

    expect(Ignoring::none()->judged($mutant))->toBe($mutant)
        ->and(Ignoring::none()->warnings())->toHaveCount(0)
        ->and(Ignoring::none()->stale(verdictsOver($mutant), Failures::none()))->toHaveCount(0);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Grade;
use NightWorksIO\MutationGate\Core\Report\Outcome;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

it('grades each outcome in a word: killed is good, escaped and untested bad, the rest need attention', function (Outcome $outcome, Grade $grade, string $label): void {
    expect($outcome->grade())->toBe($grade)
        ->and($outcome->grade()->label())->toBe($label)
        ->and($outcome->term()->value)->toBe($outcome->value)
        ->and($outcome->why())->not->toBe('');
})->with([
    'killed' => [Outcome::Killed, Grade::Good, 'Good'],
    'escaped' => [Outcome::Escaped, Grade::Bad, 'Bad'],
    'untested' => [Outcome::Untested, Grade::Bad, 'Bad'],
    'timed out' => [Outcome::TimedOut, Grade::NeedsAttention, 'Needs attention'],
    'errored' => [Outcome::Errored, Grade::NeedsAttention, 'Needs attention'],
    'left out' => [Outcome::LeftOut, Grade::NeedsAttention, 'Needs attention'],
]);

it('says why an escaped mutant is bad: a test did not notice the change', function (): void {
    expect(Outcome::Escaped->why())->toBe('A test ran the changed code but did not notice the change, so a real bug there could slip through too.')
        ->and(Outcome::TimedOut->why())->toBe('Counted as caught, but each one slows the run; one the gate cannot tell from a hang counts as escaped.');
});

it('counts the mutants of each outcome from those of each judgement, every outcome named', function (): void {
    expect(Outcome::counts([
        MutantJudgement::Survived->value => 2,
        MutantJudgement::Flaky->value => 1,
        MutantJudgement::KilledByTimeout->value => 3,
    ]))->toBe(['escaped' => 3, 'untested' => 0, 'timed-out' => 3, 'errored' => 0, 'killed' => 0, 'left-out' => 0]);
});

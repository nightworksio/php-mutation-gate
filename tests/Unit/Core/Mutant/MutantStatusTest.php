<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

it('spells each status as the reports and the ledger write it', function (): void {
    expect(array_map(static fn(MutantStatus $status): string => $status->value, MutantStatus::cases()))
        ->toBe(['killed', 'killed-by-static-analysis', 'survived', 'uncovered', 'timed-out', 'errored', 'unjudged', 'ignored-by-marker', 'skipped', 'out-of-memory']);
});

it('knows a mutant whose time ran out: timed out, or skipped for taking as long as its timeout', function (MutantStatus $status, bool $ranOut): void {
    expect($status->ranOutOfTime())->toBe($ranOut);
})->with([
    'timed out' => [MutantStatus::TimedOut, true],
    'skipped' => [MutantStatus::Skipped, true],
    'killed' => [MutantStatus::Killed, false],
    'survived' => [MutantStatus::Survived, false],
    'errored' => [MutantStatus::Errored, false],
]);

it('answers as killed where a static analyser killed it, and as itself otherwise', function (MutantStatus $status, MutantStatus $answer): void {
    expect($status->answer())->toBe($answer);
})->with([
    'killed by static analysis' => [MutantStatus::KilledByStaticAnalysis, MutantStatus::Killed],
    'killed' => [MutantStatus::Killed, MutantStatus::Killed],
    'survived' => [MutantStatus::Survived, MutantStatus::Survived],
    'timed out' => [MutantStatus::TimedOut, MutantStatus::TimedOut],
]);

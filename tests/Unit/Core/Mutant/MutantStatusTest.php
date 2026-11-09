<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

it('spells each status as the reports and the ledger write it', function (): void {
    expect(array_map(static fn(MutantStatus $status): string => $status->value, MutantStatus::cases()))
        ->toBe(['killed', 'killed-by-static-analysis', 'survived', 'uncovered', 'timed-out', 'errored', 'unjudged', 'ignored-by-marker', 'skipped', 'out-of-memory', 'fails-unmutated', 'not-reached']);
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

it('knows a mutant that let the change through: it survived, no test covered it, or no run judged it', function (MutantStatus $status, bool $through): void {
    expect($status->letsThrough())->toBe($through);
})->with([
    'killed' => [MutantStatus::Killed, false],
    'killed by static analysis' => [MutantStatus::KilledByStaticAnalysis, false],
    'survived' => [MutantStatus::Survived, true],
    'uncovered' => [MutantStatus::Uncovered, true],
    'timed out' => [MutantStatus::TimedOut, false],
    'errored' => [MutantStatus::Errored, false],
    'unjudged' => [MutantStatus::Unjudged, true],
    'ignored by a marker' => [MutantStatus::IgnoredByMarker, false],
    'skipped' => [MutantStatus::Skipped, false],
    'out of memory' => [MutantStatus::OutOfMemory, false],
    'failing unmutated' => [MutantStatus::FailsUnmutated, false],
    'not reached in a shard\'s part' => [MutantStatus::NotReached, false],
]);

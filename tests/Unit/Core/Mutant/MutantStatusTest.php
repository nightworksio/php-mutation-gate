<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;

it('spells each status as the reports and the ledger write it', function (): void {
    expect(array_map(static fn(MutantStatus $status): string => $status->value, MutantStatus::cases()))
        ->toBe(['killed', 'survived', 'uncovered', 'timed-out', 'errored', 'unjudged', 'ignored-by-marker', 'skipped']);
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

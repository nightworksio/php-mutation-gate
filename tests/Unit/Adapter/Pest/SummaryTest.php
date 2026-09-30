<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\Summary;
use NightWorksIO\MutationGate\Core\CannotJudge;

it('reads every count on Pest\'s summary line, a pending mutant as one with no result', function (): void {
    $summary = Summary::in(
        "  12 Mutations for 2 Files created\n\n"
        . "  Mutations: 5 untested, 3 uncovered, 2 pending, 1 timeout, 8 tested\n  Score:     52.94%\n",
    );

    expect($summary instanceof Summary ? [
        $summary->count(PestStatus::Untested),
        $summary->count(PestStatus::Uncovered),
        $summary->count(PestStatus::None),
        $summary->count(PestStatus::Timeout),
        $summary->count(PestStatus::Tested),
    ] : [])->toBe([5, 3, 2, 1, 8]);
});

it('counts 0 of a status Pest leaves off the line', function (): void {
    $summary = Summary::in('Mutations: 0 tested');

    $counts = $summary instanceof Summary
        ? [$summary->count(PestStatus::Untested), $summary->count(PestStatus::Tested)]
        : [];

    expect($counts)->toBe([0, 0]);
});

it('cannot judge a run that printed no summary', function (): void {
    expect(Summary::in("  FAILED  Tests\\MoneyTest\n  4 Mutations for 1 Files created"))->toEqual(CannotJudge::because(
        "Pest printed no Mutations: summary, so its mutation run did not finish. Pest said:\n"
        . "  FAILED  Tests\\MoneyTest\n  4 Mutations for 1 Files created",
    ));
});

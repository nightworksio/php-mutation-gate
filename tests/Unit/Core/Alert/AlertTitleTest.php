<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\Alert;
use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\Alert\AlertTitle;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Tests\Support\Previous;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('leads with the gate, the event, the repository and the branch', function (): void {
    expect(AlertTitle::of(Alert::of(AlertEvent::CannotJudge, Verdicts::passing(), TrendEntry::none()), Previous::ci()))
        ->toBe('mutation-gate: cannot judge on octo/gate main');
});

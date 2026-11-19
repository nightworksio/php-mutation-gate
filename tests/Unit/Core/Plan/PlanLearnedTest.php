<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\PlanLearned;
use NightWorksIO\MutationGate\Core\Runner\Multiple;

it('keeps the multiple and the pace learned, whichever is told first, and neither by default', function (): void {
    $first = PlanLearned::none()->pacing(Multiple::of(0.5))->allowing(Multiple::of(4.0));
    $second = PlanLearned::none()->allowing(Multiple::of(4.0))->pacing(Multiple::of(0.5));

    expect([$first->multiple(), $first->pace()])->toEqual([Multiple::of(4.0), Multiple::of(0.5)])
        ->and($second)->toEqual($first)
        ->and([PlanLearned::none()->multiple(), PlanLearned::none()->pace()])->toEqual([NotGiven::value(), NotGiven::value()]);
});

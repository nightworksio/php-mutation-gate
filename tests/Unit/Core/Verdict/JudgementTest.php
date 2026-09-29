<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Verdict\Judgement;

it('spells each judgement as the reports write it', function (): void {
    expect(array_map(static fn(Judgement $judgement): string => $judgement->value, Judgement::cases()))->toBe(['passed', 'failed']);
});

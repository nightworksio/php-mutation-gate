<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\Withheld;

it('names each variable as one no process the gate starts inherits from the gate', function (): void {
    foreach (ChildVariable::cases() as $variable) {
        expect(preg_match(Withheld::otherRuns()->pattern(), $variable->value))->toBe(1);
    }
});

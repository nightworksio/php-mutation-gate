<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Verdict\HeldSets;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Owners;

it('holds no owner group until it is given them, and keeps its other sets with them', function (): void {
    $newCode = NewCodeVerdicts::none();
    $security = SecurityVerdicts::none();
    $owners = Owners::verdicts();
    $sets = HeldSets::of($newCode, $security);

    expect($sets->owners())->toHaveCount(0)
        ->and(HeldSets::none()->owners())->toHaveCount(0)
        ->and(HeldSets::newCodeOnly($newCode)->owners())->toHaveCount(0)
        ->and($sets->owned($owners)->owners())->toBe($owners)
        ->and($sets->owned($owners)->newCode())->toBe($newCode)
        ->and($sets->owned($owners)->security())->toBe($security)
        ->and($sets->owners())->toHaveCount(0);
});

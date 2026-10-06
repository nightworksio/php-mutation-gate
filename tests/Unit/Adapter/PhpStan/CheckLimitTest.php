<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpStan\CheckLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('hands Symfony\'s Process its seconds, or 0, which it reads as no limit', function (): void {
    expect(new CheckLimit(Seconds::of(45))->timeout())->toBe(45.0)
        ->and(new CheckLimit()->timeout())->toBe(0.0);
});

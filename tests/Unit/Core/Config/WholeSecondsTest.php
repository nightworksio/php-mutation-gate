<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\WholeSeconds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('writes seconds a layer sets as a whole count, and nothing where it sets none', function (): void {
    expect(WholeSeconds::written(Seconds::of(90.7)))->toBe(90)
        ->and(WholeSeconds::written(Absent::setting()))->toEqual(Absent::setting());
});

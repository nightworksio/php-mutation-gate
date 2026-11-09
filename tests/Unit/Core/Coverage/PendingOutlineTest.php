<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\PendingOutline;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Test\TestIds;

it('takes the outline an engine reads only once it is asked for, and one already read as it is', function (): void {
    $taken = 0;
    $outline = MapOutline::listed(TestIds::of(), [], []);
    $pending = PendingOutline::taking(static function () use (&$taken, $outline): MapOutline {
        $taken++;

        return $outline;
    });
    $before = $taken;

    expect($before)->toBe(0)
        ->and($pending->outline())->toBe($outline)
        ->and($taken)->toBe(1)
        ->and(PendingOutline::read(NotWritten::because('declined'))->outline())->toEqual(NotWritten::because('declined'));
});

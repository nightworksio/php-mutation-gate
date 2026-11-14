<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\PendingOutline;
use NightWorksIO\MutationGate\Core\NotGiven;
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

it('knows at once why there is no outline only where it was read as none', function (): void {
    expect(PendingOutline::read(NotWritten::because('No binary.'))->noneAtOnce())->toEqual(NotWritten::because('No binary.'))
        ->and(PendingOutline::read(MapOutline::listed(TestIds::of(), [], []))->noneAtOnce())->toEqual(NotGiven::value())
        ->and(PendingOutline::taking(static fn(): NotWritten => NotWritten::because('declined'))->noneAtOnce())->toEqual(NotGiven::value());
});

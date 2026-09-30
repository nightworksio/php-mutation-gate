<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\Alert;
use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('holds its event, the verdict that made it and the entry it is told against, and finds the floors lowered since', function (): void {
    $verdict = Verdicts::passing();
    $entry = TrendEntry::none()->withFloor(Path::of('src'), Floor::of(85));
    $alert = Alert::of(AlertEvent::FloorLowered, $verdict, $entry);

    expect($alert->event())->toBe(AlertEvent::FloorLowered)
        ->and($alert->verdict())->toBe($verdict)
        ->and($alert->previous())->toBe($entry)
        ->and([...$alert->lowered()][0]->from())->toEqual(Floor::of(85));
});

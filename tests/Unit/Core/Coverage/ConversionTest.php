<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\Admission;
use NightWorksIO\MutationGate\Core\Coverage\Conversion;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Test\TestIds;

it('holds what the gate decides of a report\'s map', function (): void {
    $outline = MapOutline::listed(TestIds::of(), [], []);
    $admission = Admission::of([]);
    $at = MeasuredAt::of(Revision::ref('abc'), dirty: false);
    $keys = EntryKeys::none();
    $conversion = Conversion::of($outline, $admission, $at, $keys);

    expect($conversion->outline())->toBe($outline)
        ->and($conversion->admission())->toBe($admission)
        ->and($conversion->at())->toBe($at)
        ->and($conversion->keys())->toBe($keys);
});

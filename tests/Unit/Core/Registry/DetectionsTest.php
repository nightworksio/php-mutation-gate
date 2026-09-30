<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\CiEnvironment;
use NightWorksIO\MutationGate\Core\Ci\CiMarker;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Registry\Detections;
use NightWorksIO\MutationGate\Core\Registry\Origin;

/** Whether these variables show a marker. */
function detectionsShown(Variables $environment): Closure
{
    return CiEnvironment::of($environment)->shows(...);
}

it('takes the first plan registered that detects the CI, and none where no plan does', function (): void {
    $own = Origin::of('acme/gate');
    $detections = Detections::none()
        ->with(Name::of('one'), $own, CiMarker::saying('ONE'))
        ->with(Name::of('two'), $own, CiMarker::saying('TWO'));

    expect($detections->detected(detectionsShown(Variables::of(['TWO' => 'true', 'ONE' => 'true'])), $own))->toEqual(Name::of('one'))
        ->and($detections->detected(detectionsShown(Variables::of(['TWO' => 'true'])), $own))->toEqual(Name::of('two'))
        ->and($detections->detected(detectionsShown(Variables::of([])), $own))->toEqual(NotGiven::value())
        ->and(Detections::none()->detected(detectionsShown(Variables::of(['ONE' => 'true'])), $own))->toEqual(NotGiven::value());
});

it('lets the own package\'s plan win its CI, and another package\'s detect only where none of its own does', function (): void {
    $own = Origin::of('acme/gate');
    $theirs = Origin::of('vendor/extension');
    $detections = Detections::none()
        ->with(Name::of('claims-one'), $theirs, CiMarker::saying('ONE'))
        ->with(Name::of('their-own'), $theirs, CiMarker::setting('THEIRS'))
        ->merge(Detections::none()->with(Name::of('one'), $own, CiMarker::saying('ONE')));

    expect($detections->detected(detectionsShown(Variables::of(['ONE' => 'true'])), $own))->toEqual(Name::of('one'))
        ->and($detections->detected(detectionsShown(Variables::of(['THEIRS' => 'true'])), $own))->toEqual(Name::of('their-own'))
        ->and($detections->detected(detectionsShown(Variables::of(['THEIRS' => 'true', 'ONE' => 'true'])), $own))
        ->toEqual(Name::of('one'));
});

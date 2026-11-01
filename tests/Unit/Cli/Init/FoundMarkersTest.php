<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Init\FoundMarkers;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

/** The extensions of a project whose runner, the flows' fake, answers its markers so. */
$extensions = static fn(Markers|CannotJudge $markers): Extensions => Extensions::of(Origin::of('acme/gate'))
    ->withRunner(Name::of('fake'), static fn(): Runner => ScriptedRunner::fixture()->marking($markers));

it('counts the markers the chosen runner finds', function () use ($extensions): void {
    $markers = Markers::of(
        Marker::of('src/Money.php:11', '@infection-ignore-all', ''),
        Marker::of('src/Money.php:16', '@infection-ignore-all', ''),
    );

    expect(FoundMarkers::count($extensions($markers), Flows::settings()))->toBe(2);
});

it('counts none where the runner cannot say its markers', function () use ($extensions): void {
    expect(FoundMarkers::count($extensions(CannotJudge::because('The runner cannot read its config.')), Flows::settings()))->toBe(0);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\NativeMarkers;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$why = 'A marker hides mutants with no reason and no end, so under ignores.native: refuse a run stops at once.';
$makeInAdd = static fn(): Marker => Marker::inSource(Path::of('src/Money.php'), Line::of(7), '@pest-mutate-ignore', Enclosing::named(Path::of('src/Money.php'), 'add'));
$makeAtTop = static fn(): Marker => Marker::inSource(Path::of('src/Held.php'), Line::of(2), '@pest-mutate-ignore', Nameless::code());

it('lists each marker a refusing config would stop a run for, with the entry that replaces it', function () use ($why, $makeInAdd, $makeAtTop): void {
    $inAdd = $makeInAdd();
    $atTop = $makeAtTop();

    $observed = Observations::none()->withSettings(Configs::settings(['runner' => 'pest']))->withMarkers(Markers::of($inAdd, $atTop));
    $entry = '{"mutant": "<the id of each mutant it hides>", "reason": "<why no test can tell>"}';

    expect(NativeMarkers::in($observed))->toEqual(Findings::of(Finding::of(
        Slug::NativeMarkersRefused,
        Severity::WillFail,
        '2 native markers: src/Money.php:7 in add(), @pest-mutate-ignore; src/Held.php:2, @pest-mutate-ignore.',
        $why,
        sprintf('Replace each with its ignores.entries entry, with a reason: src/Money.php:7 with %s; src/Held.php:2 with %s. Or set ignores.native: allow.', $entry, $entry),
    )));
});

it('counts one marker as one', function () use ($makeAtTop): void {
    $atTop = $makeAtTop();

    $observed = Observations::none()->withSettings(Configs::settings(['runner' => 'pest']))->withMarkers(Markers::of($atTop));

    expect([...NativeMarkers::in($observed)][0]->found())->toBe('1 native marker: src/Held.php:2, @pest-mutate-ignore.');
});

it('finds nothing where the config allows markers, none were found, or nothing was observed', function () use ($makeInAdd): void {
    $inAdd = $makeInAdd();

    $allowing = Configs::settings(['runner' => 'pest', 'ignores' => ['native' => 'allow']]);
    $refusing = Configs::settings(['runner' => 'pest']);

    expect(NativeMarkers::in(Observations::none()->withSettings($allowing)->withMarkers(Markers::of($inAdd))))->toEqual(Findings::none())
        ->and(NativeMarkers::in(Observations::none()->withSettings($refusing)->withMarkers(Markers::none())))->toEqual(Findings::none())
        ->and(NativeMarkers::in(Observations::none()->withMarkers(Markers::of($inAdd))))->toEqual(Findings::none())
        ->and(NativeMarkers::in(Observations::none()->withSettings($refusing)))->toEqual(Findings::none());
});

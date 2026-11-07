<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BoundsOptions;
use NightWorksIO\MutationGate\Core\Config\Learned;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('reads back the bounds the flows write, and the standard where they write none', function (): void {
    $triage = Triage::of(
        limit: Seconds::of(7.0),
        most: Seconds::of(90.0),
        tighter: TighterSilence::of(Seconds::of(5.0), 'Foreach_'),
    );
    $written = Json::object(...BoundsOptions::of($triage));

    expect(BoundsOptions::read(Configs::options($written->line())))->toEqual($triage->bounds())
        ->and(BoundsOptions::read(Configs::options('{}')))->toEqual(Triage::standard()->bounds());
});

it('reads back what a run gets to start in where nothing measured it', function (): void {
    $written = Json::object(...BoundsOptions::of(Triage::of(startUp: Seconds::of(9.0))));
    $read = BoundsOptions::read(Configs::options($written->line()));

    expect($read instanceof LimitBounds ? $read->unmeasuredStartUp() : $read)->toEqual(Seconds::of(9.0));
});

it('says why where a bound is no number or the tighter mutators no list of text', function (string $options): void {
    expect(BoundsOptions::read(Configs::options($options)))->toBeInstanceOf(Problem::class);
})->with([
    'a timeout that is text' => ['{"timeout": "seven"}'],
    'a most that is text' => ['{"most": "ninety"}'],
    'a start-up that is text' => ['{"startUp": "soon"}'],
    'tighter mutators that are text' => ['{"tighterMutators": "Foreach_"}'],
]);

it('reads back a written multiple, and a learned floor and multiple, as the flows write them', function (Triage $triage): void {
    $written = Json::object(...BoundsOptions::of($triage));

    expect(BoundsOptions::read(Configs::options($written->line())))->toEqual($triage->bounds());
})->with([
    'a written multiple' => [fn(): Triage => Triage::of(multiple: 4.5)],
    'a learned floor' => [fn(): Triage => Triage::of(limit: Learned::Learned)],
    'a learned multiple' => [fn(): Triage => Triage::of(multiple: Learned::Learned)],
    'both learned' => [fn(): Triage => Triage::of(limit: Learned::Learned, multiple: Learned::Learned)],
]);

it('says why where the multiple is no number, or a learned one no flag', function (string $options): void {
    expect(BoundsOptions::read(Configs::options($options)))->toBeInstanceOf(Problem::class);
})->with([
    'a multiple that is text' => ['{"multiple": "four"}'],
    'a learned floor that is text' => ['{"learnsFloor": "yes"}'],
    'a learned multiple that is text' => ['{"learnsMultiple": "yes"}'],
]);

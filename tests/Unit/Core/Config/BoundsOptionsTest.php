<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BoundsOptions;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Format\Json;
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

it('says why where a bound is no number or the tighter mutators no list of text', function (string $options): void {
    expect(BoundsOptions::read(Configs::options($options)))->toBeInstanceOf(Problem::class);
})->with([
    'a timeout that is text' => ['{"timeout": "seven"}'],
    'a most that is text' => ['{"most": "ninety"}'],
    'tighter mutators that are text' => ['{"tighterMutators": "Foreach_"}'],
]);

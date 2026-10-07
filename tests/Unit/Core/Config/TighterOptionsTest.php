<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\TighterOptions;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('reads back the floor and the mutators the flows write, and the standard where they write none', function (): void {
    $tighter = TighterSilence::of(Seconds::of(5.0), 'RemoveArrayItem', 'Foreach_');
    $written = Json::object(TighterOptions::floor($tighter), TighterOptions::mutators($tighter));

    expect(TighterOptions::read(Configs::options($written->line())))->toEqual($tighter)
        ->and(TighterOptions::read(Configs::options('{}')))->toEqual(TighterSilence::standard());
});

it('says why where a floor is no number or the mutators no list of text', function (string $options): void {
    expect(TighterOptions::read(Configs::options($options)))->toBeInstanceOf(Problem::class);
})->with([
    'a floor that is text' => ['{"tighterFloor": "seven"}'],
    'mutators that are text' => ['{"tighterMutators": "RemoveArrayItem"}'],
]);

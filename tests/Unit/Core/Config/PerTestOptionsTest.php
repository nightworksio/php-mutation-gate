<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\BoundsOptions;
use NightWorksIO\MutationGate\Core\Config\PerTestOptions;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('reads back each test\'s own limit the flows write, off as off, and the standard where they write none', function (PerTestLimit $limit): void {
    expect(PerTestOptions::read(Configs::options(Json::object(...PerTestOptions::of($limit))->line())))->toEqual($limit);
})->with([
    'the standard' => [fn(): PerTestLimit => PerTestLimit::standard()],
    'its own' => [fn(): PerTestLimit => PerTestLimit::of(5, Seconds::of(1.5))],
    'off' => [fn(): PerTestLimit => PerTestLimit::of(0, Seconds::of(3.0))],
]);

it('reads the standard where the flows write nothing, and says why where an option is no number', function (): void {
    expect(PerTestOptions::read(Configs::options('{}')))->toEqual(PerTestLimit::standard())
        ->and(PerTestOptions::read(Configs::options('{"testMultiple": "three"}')))->toBeInstanceOf(Problem::class)
        ->and(PerTestOptions::read(Configs::options('{"testFloorSeconds": "three"}')))->toBeInstanceOf(Problem::class);
});

it('carries each test\'s own limit within the bounds the flows write, and says why where it cannot be read', function (): void {
    $triage = Triage::of(limit: Seconds::of(7.0), most: Seconds::of(90.0), perTest: 4, testFloor: Seconds::of(2));

    expect(BoundsOptions::read(Configs::options(Json::object(...BoundsOptions::of($triage))->line())))->toEqual($triage->limits()->bounds())
        ->and(BoundsOptions::read(Configs::options('{"testMultiple": "four"}')))->toBeInstanceOf(Problem::class);
});

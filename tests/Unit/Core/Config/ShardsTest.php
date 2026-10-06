<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Badge;
use NightWorksIO\MutationGate\Core\Config\Ci;
use NightWorksIO\MutationGate\Core\Config\Floors;
use NightWorksIO\MutationGate\Core\Config\Ignores;
use NightWorksIO\MutationGate\Core\Config\Local;
use NightWorksIO\MutationGate\Core\Config\Part;
use NightWorksIO\MutationGate\Core\Config\Pest;
use NightWorksIO\MutationGate\Core\Config\Proofs;
use NightWorksIO\MutationGate\Core\Config\Reach;
use NightWorksIO\MutationGate\Core\Config\Reports;
use NightWorksIO\MutationGate\Core\Config\Setup;
use NightWorksIO\MutationGate\Core\Config\Shards;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Time\Seconds;

pest()->group('holds:src/Core/Config/Shards.php');

it('stays as it is under a part of another kind', function (Part $part): void {
    expect(Shards::standard()->over($part))->toEqual(Shards::standard())
        ->and($part->over(Shards::standard()))->toEqual($part);
})->with([
    'setup' => [Setup::standard()],
    'floors' => [Floors::standard()],
    'reach' => [Reach::standard()],
    'ci' => [Ci::standard()],
    'proofs' => [Proofs::standard()],
    'triage' => [Triage::standard()],
    'ignores' => [Ignores::standard()],
    'reports' => [Reports::standard()],
    'badge' => [Badge::standard()],
    'pest' => [Pest::standard()],
    'local' => [Local::standard()],
]);

it('costs a line what the gate assumes before anything is measured, where no layer sets a cost', function (): void {
    expect(iterator_to_array(Shards::none()->secondsPerLine(), preserve_keys: true))->toBe(['' => 0.2]);
});

it('lets a later target replace the seconds a shard is cut to, a later seconds replace the target, and keeps both a part sets', function (Shards $earlier, Shards $later, Shards $laid): void {
    expect($earlier->over($later))->toEqual($laid);
})->with([
    'a later target' => [Shards::of(seconds: Seconds::of(600)), Shards::of(target: Seconds::of(1800)), Shards::of(target: Seconds::of(1800))],
    'later seconds' => [Shards::of(target: Seconds::of(1800)), Shards::of(seconds: Seconds::of(600)), Shards::of(seconds: Seconds::of(600))],
    'both, later' => [
        Shards::of(seconds: Seconds::of(600)),
        Shards::of(seconds: Seconds::of(300), target: Seconds::of(900)),
        Shards::of(seconds: Seconds::of(300), target: Seconds::of(900)),
    ],
    'neither, later' => [Shards::of(seconds: Seconds::of(600)), Shards::of(max: 3), Shards::of(seconds: Seconds::of(600), max: 3)],
]);

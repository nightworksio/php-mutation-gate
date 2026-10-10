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

describe('Shards', function (): void {
    it('stays as it is under a part of another kind', function (Part $part): void {
        expect(Shards::standard()->over($part))->toEqual(Shards::standard())
            ->and($part->over(Shards::standard()))->toEqual($part);
    })->with([
        'setup' => [fn(): Setup => Setup::standard()],
        'floors' => [fn(): Floors => Floors::standard()],
        'reach' => [fn(): Reach => Reach::standard()],
        'ci' => [fn(): Ci => Ci::standard()],
        'proofs' => [fn(): Proofs => Proofs::standard()],
        'triage' => [fn(): Triage => Triage::standard()],
        'ignores' => [fn(): Ignores => Ignores::standard()],
        'reports' => [fn(): Reports => Reports::standard()],
        'badge' => [fn(): Badge => Badge::standard()],
        'pest' => [fn(): Pest => Pest::standard()],
        'local' => [fn(): Local => Local::standard()],
    ]);

    it('costs a line what the gate assumes before anything is measured, where no layer sets a cost', function (): void {
        expect(iterator_to_array(Shards::none()->secondsPerLine(), preserve_keys: true))->toBe(['' => 0.2]);
    });

    it('lets a later target replace the seconds a shard is cut to, a later seconds replace the target, and keeps both a part sets', function (Shards $earlier, Shards $later, Shards $laid): void {
        expect($earlier->over($later))->toEqual($laid);
    })->with([
        'a later target' => [fn(): Shards => Shards::of(seconds: Seconds::of(600)), fn(): Shards => Shards::of(target: Seconds::of(1800)), fn(): Shards => Shards::of(target: Seconds::of(1800))],
        'later seconds' => [fn(): Shards => Shards::of(target: Seconds::of(1800)), fn(): Shards => Shards::of(seconds: Seconds::of(600)), fn(): Shards => Shards::of(seconds: Seconds::of(600))],
        'both, later' => [
            fn(): Shards => Shards::of(seconds: Seconds::of(600)),
            fn(): Shards => Shards::of(seconds: Seconds::of(300), target: Seconds::of(900)),
            fn(): Shards => Shards::of(seconds: Seconds::of(300), target: Seconds::of(900)),
        ],
        'neither, later' => [fn(): Shards => Shards::of(seconds: Seconds::of(600)), fn(): Shards => Shards::of(max: 3), fn(): Shards => Shards::of(seconds: Seconds::of(600), max: 3)],
    ]);
})->group('holds:src/Core/Config/Shards.php');

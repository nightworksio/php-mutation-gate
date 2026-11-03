<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Config\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Copies;

it('writes whether coverage is measured incrementally as the PHP config calls it', function (): void {
    expect([Proofs::of(incremental: true)->php(ProjectRoot::origin()), Proofs::of(incremental: false)->php(ProjectRoot::origin())])
        ->toEqual([PhpCalls::inWith('Coverage::incremental()'), PhpCalls::inWith('Coverage::full()')]);
});

it('writes the copy of a ledger a run reads as the PHP config and the JSON config name it, and the newest where none is named', function (): void {
    $origin = ProjectRoot::origin();

    expect([Proofs::of(copies: Copies::Newest)->php($origin), Proofs::of(copies: Copies::Together)->php($origin), Proofs::of(copies: Copies::Merged)->php($origin), Proofs::none()->php($origin)])
        ->toEqual([PhpCalls::inWith('Proofs::newestCopy()'), PhpCalls::inWith('Proofs::copiesTogether()'), PhpCalls::inWith('Proofs::mergedCopies()'), PhpCalls::inWith()])
        ->and(Proofs::of(copies: Copies::Together)->written($origin)->line())->toBe('{"proofs":{"copies":"together"}}')
        ->and(Proofs::none()->copies())->toBe(Copies::Newest)
        ->and(Proofs::of(copies: Copies::Merged)->over(Proofs::of(copies: Copies::Together))->copies())->toBe(Copies::Together)
        ->and(Proofs::of(copies: Copies::Merged)->over(Proofs::none())->copies())->toBe(Copies::Merged);
});

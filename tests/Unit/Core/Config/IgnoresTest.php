<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\GateMethod;
use NightWorksIO\MutationGate\Core\Config\IgnoredMutant;
use NightWorksIO\MutationGate\Core\Config\Ignores;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Judged;

$ignored = static fn(string $file): IgnoredMutant => IgnoredMutant::of(
    Judged::mutant('a', MutantJudgement::Survived, $file)->mutant()->id(),
    'Both build one list',
    Absent::setting(),
);

it('writes its entries on the gate and every other key in with, as the builder calls them', function () use ($ignored): void {
    $entry = $ignored('src/Ledger.php');
    $origin = ProjectRoot::origin();

    expect([
        Ignores::of(Listed::of($entry), maxDays: 30, native: NativeMarkers::Allow, staticEquivalence: false, notice: 7)->php($origin),
        Ignores::of(Listed::of(), native: NativeMarkers::Refuse, staticEquivalence: true)->php($origin),
    ])->toEqual([
        PhpCalls::onGate(GateMethod::Ignoring, $entry->php($origin))->and(PhpCalls::inWith(
            'Ignores::within(30)',
            'Ignores::notice(7)',
            'Ignores::allowingNativeMarkers()',
            'Equivalence::notProvenStatically()',
        )),
        PhpCalls::inWith('Ignores::refusingNativeMarkers()', 'Equivalence::provenStatically()'),
    ]);
});

it('keeps the entries of both layers, and proves equivalence statically unless a layer says not', function () use ($ignored): void {
    $earlier = Ignores::of(Listed::of($ignored('src/Ledger.php')));
    $later = Ignores::of(Listed::of($ignored('src/Money.php')), staticEquivalence: false);

    expect(count($earlier->over($later)->entries()))->toBe(2)
        ->and([Ignores::of()->staticEquivalence(), $later->staticEquivalence(), $earlier->over($later)->staticEquivalence()])
        ->toBe([true, false, false]);
});

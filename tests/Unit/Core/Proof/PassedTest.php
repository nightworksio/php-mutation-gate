<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\GateRelease;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\PassedRecord;
use NightWorksIO\MutationGate\Core\Time\Instant;

it('is a passing commit, the check it reported under, and how many of its own scope\'s proofs it used', function (): void {
    $passed = Passed::of(Revision::ref('206b4e0'), 'mutation / verdict', 2);

    expect($passed->commit())->toEqual(Revision::ref('206b4e0'))
        ->and($passed->check())->toBe('mutation / verdict')
        ->and($passed->ownScopeProofs())->toBe(2)
        ->and($passed->usedOwnScope())->toBeTrue()
        ->and(Passed::of(Revision::ref('206b4e0'), 'mutation / verdict', 0)->usedOwnScope())->toBeFalse()
        ->and(Passed::of(Revision::ref('206b4e0'), 'mutation / verdict', 1)->usedOwnScope())->toBeTrue();
});

it('used its own scope where it was measured against its own scope\'s coverage map, though it used none of its proofs', function (): void {
    $passed = Passed::of(Revision::ref('206b4e0'), 'mutation / verdict', 0)->onOwnScopeCoverage();

    expect($passed->usedOwnScope())->toBeTrue()
        ->and($passed->measuredOnOwnScope())->toBeTrue()
        ->and($passed->ownScopeProofs())->toBe(0)
        ->and(Passed::of(Revision::ref('206b4e0'), 'mutation / verdict', 0)->measuredOnOwnScope())->toBeFalse();
});

it('says when its verdict passed only where it is told, keeping all else', function (): void {
    $at = Instant::at(new DateTimeImmutable('2026-10-10T07:30:00Z'));
    $passed = Passed::of(Revision::ref('206b4e0'), 'mutation / verdict', 2)->onOwnScopeCoverage()->passedAt($at);

    expect($passed->at())->toEqual($at)
        ->and(Passed::of(Revision::ref('206b4e0'), 'mutation / verdict', 2)->at())->toEqual(NotGiven::value())
        ->and([$passed->commit(), $passed->check(), $passed->ownScopeProofs(), $passed->measuredOnOwnScope()])
        ->toEqual([Revision::ref('206b4e0'), 'mutation / verdict', 2, true]);
});

it('says which release of the gate judged it only where it is told, keeping all else and through the record', function (): void {
    $at = Instant::at(new DateTimeImmutable('2026-10-10T07:30:00Z'));
    $commit = str_repeat('a', 40);
    $unrecorded = Passed::of(Revision::ref($commit), 'mutation / verdict', 2)->onOwnScopeCoverage()->passedAt($at);
    $judged = $unrecorded->judgedBy(GateRelease::spelt('v0.1.0'));
    $read = static fn(Passed $passed): Passed => PassedRecord::read(Node::decode(JsonText::encode(PassedRecord::of($passed))));

    expect($unrecorded->gate())->toEqual(GateRelease::unrecorded())
        ->and($judged->gate())->toEqual(GateRelease::spelt('v0.1.0'))
        ->and($judged->at())->toEqual($at)
        ->and([$judged->commit(), $judged->check(), $judged->ownScopeProofs(), $judged->measuredOnOwnScope()])
        ->toEqual([Revision::ref($commit), 'mutation / verdict', 2, true])
        ->and($judged->onOwnScopeCoverage()->gate())->toEqual(GateRelease::spelt('v0.1.0'))
        ->and($judged->passedAt($at)->gate())->toEqual(GateRelease::spelt('v0.1.0'))
        ->and(PassedRecord::of($judged))->toHaveKey('gate', 'v0.1.0')
        ->and(PassedRecord::of($unrecorded))->not->toHaveKey('gate')
        ->and($read($judged)->gate())->toEqual(GateRelease::spelt('v0.1.0'))
        ->and($read($unrecorded)->gate())->toEqual(GateRelease::unrecorded());
});

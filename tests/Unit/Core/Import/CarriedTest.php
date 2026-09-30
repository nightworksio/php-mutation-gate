<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Import\Carried;
use NightWorksIO\MutationGate\Core\Import\Fate;

it('says what became of a key: imported, left in the other file, or dropped', function (): void {
    $imported = Carried::imported('minMsi', 'the floor of every tree, 80.00');
    $stays = Carried::stays('threads', 'the gate overrides it for each run');
    $dropped = Carried::dropped('maxTimeouts', 'timeouts are triaged, not capped');

    expect([$imported->key(), $imported->fate(), $imported->said('infection.json5')])
        ->toBe(['minMsi', Fate::Imported, 'minMsi: imported as the floor of every tree, 80.00'])
        ->and([$stays->key(), $stays->fate(), $stays->said('infection.json5')])
        ->toBe(['threads', Fate::Stays, 'threads: stays in infection.json5, because the gate overrides it for each run'])
        ->and([$dropped->key(), $dropped->fate(), $dropped->said('infection.json5')])
        ->toBe(['maxTimeouts', Fate::Dropped, 'maxTimeouts: dropped, because timeouts are triaged, not capped']);
});

it('deletes a key the gate imported or dropped, and keeps one that stays', function (): void {
    expect([Fate::Imported->deletes(), Fate::Stays->deletes(), Fate::Dropped->deletes()])->toBe([true, false, true]);
});

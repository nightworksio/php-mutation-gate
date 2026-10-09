<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Format\JsonFragment;
use NightWorksIO\MutationGate\Core\Migration\KeyPath;
use NightWorksIO\MutationGate\Core\Migration\MapValue;
use NightWorksIO\MutationGate\Core\Migration\Spelling;
use NightWorksIO\MutationGate\Core\NotGiven;

function mapValueDocument(string $json): JsonDocument
{
    $document = JsonDocument::parse($json);

    return $document instanceof JsonDocument ? $document : throw new LogicException('Not JSON.');
}

it('gives a key that holds the retired value the new one, and leaves any other value as it is', function (): void {
    $map = MapValue::of('timeouts.mode', 'soft', 'hard');

    expect($map->applied(mapValueDocument('{"timeouts": {"mode": "soft"}}'))->text())->toBe('{"timeouts": {"mode": "hard"}}')
        ->and($map->applied(mapValueDocument('{"timeouts": {"mode": "other"}}'))->text())->toBe('{"timeouts": {"mode": "other"}}')
        ->and([
            $map->appliesTo(mapValueDocument('{"timeouts": {"mode": "soft"}}')),
            $map->appliesTo(mapValueDocument('{"timeouts": {"mode": "hard"}}')),
            $map->appliesTo(mapValueDocument('{}')),
        ])->toBe([true, false, false]);
});

it('tells a number, a truth and a string apart', function (): void {
    expect(MapValue::of('shards.seconds', 1, 600)->appliesTo(mapValueDocument('{"shards": {"seconds": "1"}}')))->toBeFalse()
        ->and(MapValue::of('run.full', old: true, new: false)->applied(mapValueDocument('{"run": {"full": true}}'))->text())
        ->toBe('{"run": {"full": false}}');
});

it('says what it changed, where, its two values, and the builder call whose literal it rewrites', function (): void {
    $spelling = Spelling::retiring('Timeouts::mode');
    $map = MapValue::of('timeouts.mode', 'soft', 'hard');

    expect($map->change())->toBe('`timeouts.mode`: "soft" became "hard"')
        ->and($map->at())->toEqual(KeyPath::of('timeouts.mode'))
        ->and([$map->old(), $map->new()])->toEqual([JsonFragment::of('"soft"'), JsonFragment::of('"hard"')])
        ->and($map->spelling())->toEqual(NotGiven::value())
        ->and($map->spelt($spelling)->spelling())->toBe($spelling);
});

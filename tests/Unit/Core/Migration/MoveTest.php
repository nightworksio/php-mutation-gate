<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Migration\KeyPath;
use NightWorksIO\MutationGate\Core\Migration\Move;
use NightWorksIO\MutationGate\Core\Migration\Spelling;
use NightWorksIO\MutationGate\Core\NotGiven;

function moveDocument(string $json): JsonDocument
{
    $document = JsonDocument::parse($json);

    return $document instanceof JsonDocument ? $document : throw new LogicException('Not JSON.');
}

it('takes a key and its value elsewhere, with the objects the new place needs, and drops the object it empties', function (): void {
    $move = Move::of('scope.everything', 'reach.everything');

    expect($move->applied(moveDocument("{\n    \"scope\": { \"everything\": [\"a\"] }\n}\n"))->text())
        ->toBe("{\n    \"reach\": {\n        \"everything\": [\"a\"]\n    }\n}\n")
        ->and($move->applied(moveDocument('{"scope": {"everything": [], "x": 1}, "reach": {"everything": []}}'))->text())
        ->toBe('{"scope": {"everything": [], "x": 1}, "reach": {"everything": []}}')
        ->and([$move->appliesTo(moveDocument('{"scope": {"everything": []}}')), $move->appliesTo(moveDocument('{}'))])->toBe([true, false]);
});

it('says what it changed, where, and the builder call it retires where it names one', function (): void {
    $spelling = Spelling::replacing('Reach::all', 'Reach::everything');
    $move = Move::of('scope.everything', 'reach.everything');

    expect($move->change())->toBe('`scope.everything` became `reach.everything`')
        ->and($move->at())->toEqual(KeyPath::of('scope.everything'))
        ->and($move->spelling())->toEqual(NotGiven::value())
        ->and($move->spelt($spelling)->spelling())->toBe($spelling);
});

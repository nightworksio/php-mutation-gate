<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Migration\KeyPath;
use NightWorksIO\MutationGate\Core\Migration\Rename;
use NightWorksIO\MutationGate\Core\Migration\Spelling;
use NightWorksIO\MutationGate\Core\NotGiven;

function renameDocument(string $json): JsonDocument
{
    $document = JsonDocument::parse($json);

    return $document instanceof JsonDocument ? $document : throw new LogicException('Not JSON.');
}

it('gives a key another name where it stands, and moves it where the new name is under another parent', function (): void {
    $file = renameDocument("{\n    \"runnr\": \"pest\",\n    \"reach\": { \"all\": [] }\n}\n");

    expect(Rename::of('runnr', 'runner')->applied($file)->text())->toBe("{\n    \"runner\": \"pest\",\n    \"reach\": { \"all\": [] }\n}\n")
        ->and(Rename::of('reach.all', 'scope.all')->applied($file)->text())
        ->toBe("{\n    \"runnr\": \"pest\",\n    \"scope\": {\n        \"all\": []\n    }\n}\n");
});

it('changes nothing where the key is gone, or the new one is already written, and still applies to the second', function (): void {
    $both = renameDocument('{"runnr": "pest", "runner": "phpunit"}');
    $rename = Rename::of('runnr', 'runner');

    expect($rename->applied(renameDocument('{"runner": "pest"}'))->text())->toBe('{"runner": "pest"}')
        ->and($rename->applied($both)->text())->toBe('{"runnr": "pest", "runner": "phpunit"}')
        ->and([$rename->appliesTo($both), $rename->appliesTo(renameDocument('{"runner": "pest"}'))])->toBe([true, false]);
});

it('says what it changed, where, and the builder call it retires where it names one', function (): void {
    $spelling = Spelling::replacing('Gate::runnr', 'Gate::runner');
    $rename = Rename::of('runnr', 'runner');

    expect($rename->change())->toBe('`runnr` became `runner`')
        ->and($rename->at())->toEqual(KeyPath::of('runnr'))
        ->and($rename->spelling())->toEqual(NotGiven::value())
        ->and($rename->spelt($spelling)->spelling())->toBe($spelling);
});

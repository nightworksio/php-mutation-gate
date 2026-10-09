<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Migration\KeyPath;
use NightWorksIO\MutationGate\Core\Migration\Remove;
use NightWorksIO\MutationGate\Core\Migration\Spelling;
use NightWorksIO\MutationGate\Core\NotGiven;

function removeDocument(string $json): JsonDocument
{
    $document = JsonDocument::parse($json);

    return $document instanceof JsonDocument ? $document : throw new LogicException('Not JSON.');
}

it('takes a key out with its value, and says what went, why and where', function (): void {
    $file = removeDocument('{"runner": "pest", "legacy": true}');
    $remove = Remove::of('legacy', 'nothing reads it');
    $spelling = Spelling::retiring('Gate::legacy');

    expect($remove->applied($file)->text())->toBe('{"runner": "pest"}')
        ->and([$remove->appliesTo($file), $remove->appliesTo($remove->applied($file))])->toBe([true, false])
        ->and($remove->change())->toBe('`legacy` was removed (nothing reads it)')
        ->and($remove->at())->toEqual(KeyPath::of('legacy'))
        ->and($remove->spelling())->toEqual(NotGiven::value())
        ->and($remove->spelt($spelling)->spelling())->toBe($spelling);
});

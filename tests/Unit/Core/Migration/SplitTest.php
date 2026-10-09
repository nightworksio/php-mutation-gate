<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\JsonDocument;
use NightWorksIO\MutationGate\Core\Migration\KeyPath;
use NightWorksIO\MutationGate\Core\Migration\Spelling;
use NightWorksIO\MutationGate\Core\Migration\Split;
use NightWorksIO\MutationGate\Core\Migration\SplitPart;
use NightWorksIO\MutationGate\Core\NotGiven;

function splitDocument(string $json): JsonDocument
{
    $document = JsonDocument::parse($json);

    return $document instanceof JsonDocument ? $document : throw new LogicException('Not JSON.');
}

function splitLimits(): Split
{
    return Split::of('limits', SplitPart::of('seconds', 'timeouts.seconds'), SplitPart::of('most', 'timeouts.most'));
}

it('moves each named part of an object to its own key, and takes the object away once it holds nothing', function (): void {
    expect(splitLimits()->applied(splitDocument('{"limits": {"seconds": 5, "most": 60}}'))->text())
        ->toBe("{\"timeouts\": {\n    \"seconds\": 5,\n    \"most\": 60\n}}")
        ->and(splitLimits()->appliesTo(splitLimits()->applied(splitDocument("{\n    \"limits\": {\n        \"seconds\": 5,\n        \"most\": 60\n    }\n}"))))
        ->toBeFalse();
});

it('leaves a part it does not name under the object, a part whose key is written, and a scalar, each for a hand edit', function (): void {
    $kept = splitLimits()->applied(splitDocument("{\n    \"limits\": {\n        \"seconds\": 5,\n        \"other\": 1\n    }\n}"));

    expect($kept->has(KeyPath::of('limits.other')))->toBeTrue()
        ->and($kept->has(KeyPath::of('timeouts.seconds')))->toBeTrue()
        ->and(splitLimits()->appliesTo($kept))->toBeTrue()
        ->and(splitLimits()->applied(splitDocument('{"limits": 5}'))->text())->toBe('{"limits": 5}')
        ->and(splitLimits()->applied(splitDocument('{"limits": {"seconds": 5}, "timeouts": {"seconds": 9}}'))->text())
        ->toBe('{"limits": {"seconds": 5}, "timeouts": {"seconds": 9}}')
        ->and(splitLimits()->applied(splitDocument('{}'))->text())->toBe('{}');
});

it('says what it changed, where, and the builder call it retires where it names one', function (): void {
    $spelling = Spelling::retiring('Timeouts::limits');

    expect(splitLimits()->change())->toBe('`limits` became `timeouts.seconds` and `timeouts.most`')
        ->and(splitLimits()->at())->toEqual(KeyPath::of('limits'))
        ->and(splitLimits()->spelling())->toEqual(NotGiven::value())
        ->and(splitLimits()->spelt($spelling)->spelling())->toBe($spelling);
});

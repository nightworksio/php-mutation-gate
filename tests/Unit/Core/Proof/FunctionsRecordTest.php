<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpan;
use NightWorksIO\MutationGate\Core\Heat\FunctionSpans;
use NightWorksIO\MutationGate\Core\Proof\FunctionsRecord;

it('writes each span as its name and lines, and nothing for none, and reads back what it wrote', function (): void {
    $spans = FunctionSpans::of(
        FunctionSpan::of('Money::add', Line::of(4), Line::of(7)),
        FunctionSpan::of('helper', Line::of(15), Line::of(18)),
    );

    expect(FunctionsRecord::of($spans))->toBe(['functions' => [['Money::add', 4, 7], ['helper', 15, 18]]])
        ->and(FunctionsRecord::of(FunctionSpans::none()))->toBe([])
        ->and(FunctionsRecord::read(Node::decode((string) json_encode(FunctionsRecord::of($spans)))->field(FunctionsRecord::FIELD)))
        ->toEqual($spans);
});

it('drops every span that is not in shape, keeps the rest, and reads none from a proof without the field', function (string $json): void {
    expect(FunctionsRecord::read(Node::decode($json)->field(FunctionsRecord::FIELD)))
        ->toEqual(FunctionSpans::of(FunctionSpan::of('kept', Line::of(1), Line::of(2))));
})->with([
    'too few items' => ['{"functions": [["short", 1], ["kept", 1, 2]]}'],
    'too many items' => ['{"functions": [["kept", 1, 2], ["long", 1, 2, 3]]}'],
    'a line that is no number' => ['{"functions": [["kept", 1, 2], ["text", "a", 2]]}'],
    'not a list' => ['{"functions": [["kept", 1, 2], {"name": "x"}]}'],
]);

it('reads none from a field that is no list, or a proof without one', function (): void {
    expect(FunctionsRecord::read(Node::decode('{"functions": "x"}')->field(FunctionsRecord::FIELD)))->toEqual(FunctionSpans::none())
        ->and(FunctionsRecord::read(Node::decode('{}')->field(FunctionsRecord::FIELD)))->toEqual(FunctionSpans::none());
});

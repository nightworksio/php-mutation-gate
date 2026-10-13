<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\JsonFragment;
use NightWorksIO\MutationGate\Core\Format\JsonIndent;

it('reads the indentation of the line an offset stands on, from the line\'s start alone', function (string $json, int $offset, string $indent): void {
    expect(JsonIndent::at($json, $offset))->toBe($indent);
})->with([
    'the first line' => ["  {\"a\": 1}", 5, '  '],
    'a later line' => ["{\n\t  \"a\": 1\n}", 6, "\t  "],
    'a line with none' => ["{\n\"a\": 1\n}", 4, ''],
]);

it('writes a member under each further key, one object deeper for each', function (): void {
    expect(JsonIndent::standard()->member('a', ['b', 'c'], JsonFragment::of('1'), ''))
        ->toBe("\"a\": {\n    \"b\": {\n        \"c\": 1\n    }\n}");
});

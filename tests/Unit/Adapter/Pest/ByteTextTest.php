<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\ByteText;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

it('gives back the bytes its text holds, whichever bytes they are', function (): void {
    $bytes = "\x00\xffschema\n";

    expect(ByteText::bytes(ByteText::of($bytes), 'schemata.a'))->toBe($bytes);
});

it('refuses a text that holds no base64 bytes, at the place it was read', function (string $text): void {
    expect(static fn(): string => ByteText::bytes($text, 'schemata.a'))->toThrow(NotInShape::class, 'schemata.a is not base64 bytes.');
})->with([
    'a character base64 has none of' => ['ab*d'],
    'a cut-off text' => ['abc'],
]);

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\JsonText;

it('writes one key per line, with slashes, text and whole-number floats as they are', function (): void {
    expect(JsonText::encode(['path' => 'src/Ärger.php', 'seconds' => 2.0, 'lines' => [1]]))
        ->toBe("{\n    \"path\": \"src/Ärger.php\",\n    \"seconds\": 2.0,\n    \"lines\": [\n        1\n    ]\n}");
});

it('replaces bytes that are not UTF-8 rather than refusing them', function (): void {
    expect(JsonText::encode(['diff' => "a\xB1b"]))->toBe("{\n    \"diff\": \"a\u{FFFD}b\"\n}");
});

it('writes compactly on one line, with slashes, text and whole-number floats as they are', function (): void {
    expect(JsonText::compact(['path' => 'src/Ärger.php', 'seconds' => 2.0, 'lines' => [1], 'diff' => "a\xB1b"]))
        ->toBe("{\"path\":\"src/Ärger.php\",\"seconds\":2.0,\"lines\":[1],\"diff\":\"a\u{FFFD}b\"}");
});

it('writes an object a member at a time from JSON text, as the whole would be written compactly', function (): void {
    $members = static function (): Generator {
        yield 'path' => JsonText::compact(['src/Ärger.php']);
        yield 'seconds' => '2.0';
        yield '12' => JsonText::compact(['one' => 1]);
    };

    expect(JsonText::object($members()))->toBe(JsonText::compact([
        'path' => ['src/Ärger.php'],
        'seconds' => 2.0,
        '12' => ['one' => 1],
    ]))
        ->and(JsonText::object([]))->toBe('{}')
        ->and(JsonText::object(['a' => '1', '12' => '2']))->toBe('{"a":1,"12":2}');
});

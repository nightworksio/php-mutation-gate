<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Json;

it('writes one key per line, with slashes, text and whole-number floats as they are', function (): void {
    expect(Json::encode(['path' => 'src/Ärger.php', 'seconds' => 2.0, 'lines' => [1]]))
        ->toBe("{\n    \"path\": \"src/Ärger.php\",\n    \"seconds\": 2.0,\n    \"lines\": [\n        1\n    ]\n}");
});

it('replaces bytes that are not UTF-8 rather than refusing them', function (): void {
    expect(Json::encode(['diff' => "a\xB1b"]))->toBe("{\n    \"diff\": \"a\u{FFFD}b\"\n}");
});

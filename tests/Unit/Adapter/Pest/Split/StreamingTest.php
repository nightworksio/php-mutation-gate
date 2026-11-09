<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Streaming;

it('cuts the test files into parts of about the same time, longest first', function (): void {
    $units = [
        'tests/A.php' => ['files' => ['tests/A.php'], 'tests' => ['A::one', 'A::two']],
        'tests/B.php' => ['files' => ['tests/B.php'], 'tests' => ['B::one']],
        'tests/C.php' => ['files' => ['tests/C.php', 'tests/Helper.php'], 'tests' => ['C::one']],
    ];
    $times = ['A::one' => 2.0, 'A::two' => 2.0, 'B::one' => 3.0, 'C::one' => 1.0];

    expect(Streaming::parts($units, $times, 2))->toBe([
        ['tests/A.php'],
        ['tests/B.php', 'tests/C.php', 'tests/Helper.php'],
    ]);
});

it('makes no more parts than there are test files', function (): void {
    $units = ['tests/A.php' => ['files' => ['tests/A.php'], 'tests' => ['A::one']]];

    expect(Streaming::parts($units, [], 8))->toBe([['tests/A.php']]);
});

it('keeps a file two parts need in each, once', function (): void {
    $units = [
        'tests/A.php' => ['files' => ['tests/A.php', 'tests/Shared.php'], 'tests' => ['A::one']],
        'tests/B.php' => ['files' => ['tests/B.php', 'tests/Shared.php'], 'tests' => ['B::one']],
    ];

    expect(Streaming::parts($units, ['A::one' => 1.0, 'B::one' => 1.0], 2))->toBe([
        ['tests/A.php', 'tests/Shared.php'],
        ['tests/B.php', 'tests/Shared.php'],
    ]);
});

it('runs the whole suite in one part where it knows no test file', function (): void {
    expect(Streaming::parts([], [], 4))->toBe([[]]);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;

it('reads a display_errors value as PHP does: a word it names, in any case, or else a number', function (
    string $value,
    ErrorDisplay $display,
): void {
    expect(ErrorDisplay::read($value))->toBe($display);
})->with([
    'on' => ['On', ErrorDisplay::Stdout],
    'yes' => ['yes', ErrorDisplay::Stdout],
    'true' => ['TRUE', ErrorDisplay::Stdout],
    'stdout' => ['stdout', ErrorDisplay::Stdout],
    'stderr' => ['StdErr', ErrorDisplay::Stderr],
    'one' => ['1', ErrorDisplay::Stdout],
    'two' => ['2', ErrorDisplay::Stderr],
    'any other number' => ['3', ErrorDisplay::Stdout],
    'zero' => ['0', ErrorDisplay::Nowhere],
    'off' => ['off', ErrorDisplay::Nowhere],
    'nothing' => ['', ErrorDisplay::Nowhere],
]);

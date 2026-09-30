<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\WholeNumber;

it('reads a whole number of one or more written in digits alone', function (string $text): void {
    expect(WholeNumber::isPositive($text))->toBeTrue();
})->with(['1', '9', '10', '1200']);

it('refuses nought, a leading nought, a sign, and anything around the digits', function (string $text): void {
    expect(WholeNumber::isPositive($text))->toBeFalse();
})->with(['', '0', '03', '-1', '+1', ' 2', "2\n", '2x', '1.5']);

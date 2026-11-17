<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamFallbacks;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamRead;

/** Fallbacks that took these lines, each decoded. */
function fallbacksOf(string ...$lines): StreamFallbacks
{
    $fallbacks = new StreamFallbacks();

    foreach ($lines as $line) {
        $read = json_decode($line, associative: true);
        $fallbacks->take(StreamRead::of(is_array($read) ? $read : []));
    }

    return $fallbacks;
}

it('leaves every mutant to a test the stream forked nothing in, and only its mutant to a test that forked it nowhere', function (): void {
    $fallbacks = fallbacksOf(StreamLine::fallback('T::one'), StreamLine::fallback('T::two', 'm'));

    expect($fallbacks->leaves('m', 'T::one'))->toBeTrue()
        ->and($fallbacks->leaves('n', 'T::one'))->toBeTrue()
        ->and($fallbacks->leaves('m', 'T::two'))->toBeTrue()
        ->and($fallbacks->leaves('n', 'T::two'))->toBeFalse()
        ->and($fallbacks->leaves('m', 'T::three'))->toBeFalse();
});

it('takes no fallback from a line of another kind', function (): void {
    $fallbacks = fallbacksOf(StreamLine::parent('T::one', passed: true, seconds: 0.1), StreamLine::timedOut('m', 'T::two'));

    expect($fallbacks->leaves('m', 'T::one'))->toBeFalse()
        ->and($fallbacks->leaves('m', 'T::two'))->toBeFalse();
});

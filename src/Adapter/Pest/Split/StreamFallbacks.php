<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

/**
 * The tests split streams forked nothing in, and the tests they forked a
 * mutant nowhere in, as their results say (ADR-0117, ADR-0175): a covering
 * test of either kind leaves its mutant for its own run.
 *
 * @internal
 */
final class StreamFallbacks
{
    /** @var array<string, true> the tests a stream forked nothing in, by their ids */
    private array $tests = [];

    /** @var array<string, array<string, true>> the tests a stream forked a mutant nowhere in, by mutant */
    private array $mutants = [];

    /** These fallbacks, with what one line says, where it names a fallback. */
    public function take(StreamRead $read): void
    {
        if ($read->kind !== StreamLine::FALLBACK) {
            return;
        }

        if ($read->mutant === '') {
            $this->tests[$read->test] = true;

            return;
        }

        $this->mutants[$read->mutant][$read->test] = true;
    }

    /** Whether a stream forked nothing in this test, or this mutant nowhere in it. */
    public function leaves(string $mutant, string $test): bool
    {
        return array_key_exists($test, $this->tests)
            || (array_key_exists($mutant, $this->mutants) && array_key_exists($test, $this->mutants[$mutant]));
    }
}

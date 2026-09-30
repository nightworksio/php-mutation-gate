<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;

/**
 * PHP's fatal error for a process that ran out of its `memory_limit`, which
 * names the limit in bytes: the one sign both runners leave of a process the
 * memory cap stopped (ADR-0004, decision 9).
 */
final readonly class Exhaustion
{
    /** What to do where the suite does not fit under the cap, as every refusal over it says. */
    public const string ADVICE = 'Raise runner.memory; doctor --measure says what the suite needs.';

    /** PHP's message, with the limit it ran out of in bytes. */
    private const string MESSAGE = '/Allowed memory size of (?<bytes>\d+) bytes exhausted/';

    /** The limit a PHP process ran out of, where this text holds PHP's fatal error for it. */
    public static function in(string $text): MemoryCap|NotGiven
    {
        if (preg_match(self::MESSAGE, $text, $found) !== 1) {
            return NotGiven::value();
        }

        $bytes = (int) $found['bytes'];

        return (string) $bytes === $found['bytes'] && $bytes > 0
            ? MemoryCap::of($bytes, MemoryUnit::Bytes)
            : NotGiven::value();
    }

    /**
     * Whether a process ran out of exactly this cap, as the limit it ran out
     * of says in bytes. A limit the project set itself is not the cap.
     */
    public static function isOf(MemoryCap|NotGiven $limit, MemoryCap $cap): bool
    {
        return $limit instanceof MemoryCap && $cap->caps() && $limit->bytes() === $cap->bytes();
    }
}

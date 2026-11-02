<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

/**
 * Whether an outcome is good, needs attention or is bad, said in a word
 * beside every count, so that no reader needs the colour to tell (ADR-0009).
 * The value is the word the digest writes.
 *
 * @internal
 */
enum Grade: string
{
    case Good = 'good';
    case NeedsAttention = 'attention';
    case Bad = 'bad';

    /** The word the HTML report shows. */
    public function label(): string
    {
        return match ($this) {
            self::Good => 'Good',
            self::NeedsAttention => 'Needs attention',
            self::Bad => 'Bad',
        };
    }
}

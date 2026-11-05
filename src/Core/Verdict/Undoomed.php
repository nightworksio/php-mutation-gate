<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/**
 * A run no survivor has yet made certain to fail, or one that watches for none.
 *
 * @internal
 */
final readonly class Undoomed
{
    public static function run(): self
    {
        return new self();
    }

    /** Whether the shard stopped once its run could not pass: never, where nothing doomed it. */
    public function stopped(): bool
    {
        return false;
    }
}

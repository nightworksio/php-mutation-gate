<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Outbreak;

/**
 * What one child of `outbreak.so` answered (see OpArrays): the extension's
 * version, and the digest of each program it compiled, from the first on.
 *
 * @template TKey of array-key
 *
 * @internal
 */
final readonly class Answer
{
    /** @param array<TKey, string> $digests */
    public function __construct(public string $version, public array $digests)
    {
    }
}

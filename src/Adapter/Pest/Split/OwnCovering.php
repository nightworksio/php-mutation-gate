<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;

use Pest\Mutate\Mutation;

/**
 * The covering tests a mutant's own run selects, where a run cuts its test
 * files across its shards (ADR-0117): those of the shard's part alone, for a
 * mutant the part's streams judge and leave for its own run, so each part
 * runs only its own tests of it; every covering test otherwise. Kept in Pest's
 * own process, from the streams to the mutant's own run.
 *
 * @internal
 */
final class OwnCovering
{
    /** @var array<string, list<string>> the covering tests of the shard's part, by Pest's id of the mutant */
    private static array $held = [];

    /**
     * Narrows the mutant's own run, should a stream leave it, to these tests.
     *
     * @param list<string> $tests
     */
    public static function narrow(Mutation $mutation, array $tests): void
    {
        self::$held[$mutation->id] = $tests;
    }

    /**
     * The covering tests the mutant's own run selects of these.
     *
     * @param  list<string> $covering
     * @return list<string>
     */
    public static function of(Mutation $mutation, array $covering): array
    {
        return array_key_exists($mutation->id, self::$held) ? self::$held[$mutation->id] : $covering;
    }
}

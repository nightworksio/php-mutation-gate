<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_map;
use function in_array;

use NightWorksIO\MutationGate\Adapter\Pest\ReplayVerdict;

/** Replays of kills' own runs that a test answers: each stands but those of keys it gives no time, and each run is kept. */
final class ReplayRuns
{
    /** @var list<list<string>> the keys of each run, in turn */
    private array $ran = [];

    /** @param list<string> $timeless the keys whose replays have no time */
    public function __construct(private readonly array $timeless)
    {
    }

    /**
     * What the replays of these keys say, in their order.
     *
     * @param  non-empty-list<string> $keys
     * @return list<ReplayVerdict>
     */
    public function run(array $keys): array
    {
        $this->ran[] = $keys;

        return array_map(
            fn(string $key): ReplayVerdict => in_array($key, $this->timeless, strict: true) ? ReplayVerdict::NoTime : ReplayVerdict::Stands,
            $keys,
        );
    }

    /** @return list<list<string>> the keys of each run, in turn */
    public function ran(): array
    {
        return $this->ran;
    }
}

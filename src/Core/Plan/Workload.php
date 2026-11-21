<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function array_values;
use function count;

use Countable;

use function ksort;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;

/**
 * The units a plan cuts into shards, each weighed, and the coverage map
 * that prices each part of the test files a run cuts.
 *
 * @internal
 */
final readonly class Workload implements Countable
{
    /** @param array<Weighed> $units */
    private function __construct(private array $units, private CoverageMap $map)
    {
    }

    public static function of(Weighed ...$units): self
    {
        return new self($units, CoverageMap::of());
    }

    /** These units, each part of the test files a run cuts priced by the tests this map times as covering them. */
    public function timedBy(CoverageMap $map): self
    {
        return new self($this->units, $map);
    }

    /**
     * The units of each package, packages in path order.
     *
     * @return list<PackageWork>
     */
    public function byPackage(): array
    {
        $packages = [];

        foreach ($this->units as $unit) {
            $packages[$unit->package()->path()->value()][] = $unit;
        }

        ksort($packages, SORT_STRING);

        return array_values(array_map(
            fn(array $units): PackageWork => PackageWork::of(...$units)->timedBy($this->map),
            $packages,
        ));
    }

    public function count(): int
    {
        return count($this->units);
    }
}

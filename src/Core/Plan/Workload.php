<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_map;
use function array_values;
use function count;

use Countable;

use function ksort;

/** The units a plan cuts into shards, each weighed. */
final readonly class Workload implements Countable
{
    /** @param array<Weighed> $units */
    private function __construct(private array $units)
    {
    }

    public static function of(Weighed ...$units): self
    {
        return new self($units);
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

        return array_values(array_map(static fn(array $units): PackageWork => PackageWork::of(...$units), $packages));
    }

    public function count(): int
    {
        return count($this->units);
    }
}

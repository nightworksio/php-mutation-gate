<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function count;

use Countable;

use function ksort;
use function usort;

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
     * The units of each package, by the package's path in path order, and
     * each package's units in path order.
     *
     * @return array<string, list<Weighed>>
     */
    public function byPackage(): array
    {
        $packages = [];

        foreach ($this->units as $unit) {
            $packages[$unit->package()->path()->value()][] = $unit;
        }

        ksort($packages, SORT_STRING);

        foreach ($packages as $package => $units) {
            usort(
                $units,
                static fn(Weighed $one, Weighed $other): int => $one->unit()->path()->value()
                    <=> $other->unit()->path()->value(),
            );
            $packages[$package] = $units;
        }

        return $packages;
    }

    public function count(): int
    {
        return count($this->units);
    }
}

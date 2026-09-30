<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules\OneHome;

use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function in_array;

/**
 * A class constant holding a value that another class also holds: the
 * constant, and the other homes of its value.
 */
final readonly class Clash
{
    /** @param list<Home> $others */
    private function __construct(public Home $home, public array $others)
    {
    }

    /**
     * Every clash among the values a collector gathered, file by file. A
     * value held in one class only is no clash, an enum case is reported
     * only as another's home, and a coincidence is left out altogether.
     *
     * @param array<string, list<list<array{string, string, int, bool}>>> $collected
     * @param list<string>                                                 $coincidences
     *
     * @return list<self>
     */
    public static function among(array $collected, array $coincidences): array
    {
        $clashes = [];

        foreach (self::homesByValue($collected, $coincidences) as $homes) {
            if (count(array_unique(array_map(static fn(Home $home): string => $home->class(), $homes))) < 2) {
                continue;
            }

            foreach (array_filter($homes, static fn(Home $home): bool => ! $home->isCase) as $home) {
                $clashes[] = new self($home, array_values(array_filter($homes, static fn(Home $other): bool => $other->name !== $home->name)));
            }
        }

        return $clashes;
    }

    /**
     * @param array<string, list<list<array{string, string, int, bool}>>> $collected
     * @param list<string>                                                 $coincidences
     *
     * @return array<list<Home>>
     */
    private static function homesByValue(array $collected, array $coincidences): array
    {
        $homes = [];

        foreach ($collected as $file => $declarations) {
            foreach (array_merge(...$declarations) as [$value, $name, $line, $isCase]) {
                if (! in_array($name, $coincidences, strict: true)) {
                    $homes[$value][] = new Home($value, $name, $file, $line, $isCase);
                }
            }
        }

        return $homes;
    }
}

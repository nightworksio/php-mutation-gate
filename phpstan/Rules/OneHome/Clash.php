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
use function ksort;

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
     * Every clash among the values a collector gathered, file by file, each
     * value's homes in order of their names. A value held in one class only
     * is no clash, an enum case is reported only as another's home, and a
     * coincidence is left out altogether.
     *
     * @param array<string, list<list<array{string, string, int, bool}>>> $collected
     * @param list<string>                                                 $coincidences
     *
     * @return list<self>
     */
    public static function among(array $collected, array $coincidences): array
    {
        $clashes = [];

        foreach (self::homesByValue($collected, $coincidences) as $byName) {
            ksort($byName);
            $homes = array_values($byName);

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
     * The homes of each value, each constant once, however many of its array's items hold the value.
     *
     * @param array<string, list<list<array{string, string, int, bool}>>> $collected
     * @param list<string>                                                 $coincidences
     *
     * @return array<array<string, Home>>
     */
    private static function homesByValue(array $collected, array $coincidences): array
    {
        $homes = [];

        foreach ($collected as $file => $declarations) {
            foreach (array_merge(...$declarations) as [$value, $name, $line, $isCase]) {
                if (! in_array($name, $coincidences, strict: true)) {
                    $homes[$value][$name] = new Home($value, $name, $file, $line, $isCase);
                }
            }
        }

        return $homes;
    }
}

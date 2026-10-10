<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use NightWorksIO\MutationGate\Core\File\Lines;

/**
 * The executable lines of one file of a coverage map: each line some test
 * ran, by number in ascending order, with a name for the set of tests that ran
 * it, the same for the same set anywhere in the map, so what is worked out of
 * a set is worked out once; and the lines no test ran.
 */
final readonly class LineSets
{
    /** @param array<int, string> $covered each covered line's set, by number, ascending */
    private function __construct(private LineTests $map, private array $covered, private Lines $missed)
    {
    }

    /** @param array<int, string> $covered each covered line's set, by number, ascending */
    public static function of(LineTests $map, array $covered, Lines $missed): self
    {
        return new self($map, $covered, $missed);
    }

    /** @return array<int, string> each covered line's set, by number, ascending */
    public function covered(): array
    {
        return $this->covered;
    }

    /** The executable lines no test ran. */
    public function missed(): Lines
    {
        return $this->missed;
    }

    /**
     * The ids of the tests of a set this names, each once, in byte order.
     *
     * @return list<string>
     */
    public function idsOf(string $set): array
    {
        return $this->map->idsOfSet($set);
    }
}

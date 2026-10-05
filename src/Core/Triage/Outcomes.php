<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Triage;

use function array_key_exists;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * One mutant over repeated runs of its unit: what each run gave it, its
 * status or that it did not make it, grouped by what it gave with the runs
 * that gave it and the tests that killed it in them.
 */
final readonly class Outcomes
{
    /** @param list<Mutant|NotMade> $outcomes what each run gave it, the first run first */
    private function __construct(private Mutant $mutant, private array $outcomes)
    {
    }

    /** @param list<Mutant|NotMade> $outcomes */
    public static function over(Mutant $mutant, array $outcomes): self
    {
        return new self($mutant, $outcomes);
    }

    /** The mutant as the first run that made it reported it. */
    public function mutant(): Mutant
    {
        return $this->mutant;
    }

    public function didVary(): bool
    {
        return count($this->grouped()) > 1;
    }

    /**
     * @return list<array{Mutant|NotMade, list<int>, TestIds}> each outcome the runs gave, as the first of them
     *                                                         gave it, the runs that gave it, counted from 1,
     *                                                         and the tests that killed it in them
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->outcomes as $index => $outcome) {
            $key = $outcome instanceof Mutant ? $outcome->status()->value : '';
            $group = array_key_exists($key, $groups) ? $groups[$key] : [$outcome, [], TestIds::none()];
            $groups[$key] = [$group[0], [...$group[1], $index + 1], $this->killed($group[2], $outcome)];
        }

        return array_values($groups);
    }

    private function killed(TestIds $killers, Mutant|NotMade $outcome): TestIds
    {
        return $outcome instanceof Mutant ? $killers->and($outcome->killers()) : $killers;
    }
}

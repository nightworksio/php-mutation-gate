<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

/**
 * The whole tests no removal may take: each that alone covers a line, and
 * each that alone among its holding group covers a line of what the group
 * holds, whose removal would leave the group short of the coverage it must
 * have (ADR-0014, decision 8, and ADR-0005, decision 10).
 */
final readonly class MustStay
{
    /** @param array<string, true> $tests by whole test */
    private function __construct(private array $tests)
    {
    }

    public static function of(Verdict $verdict): self
    {
        $names = $verdict->matrix()->names();
        $groups = self::holdingGroups($verdict);
        $stay = [];

        foreach ($verdict->matrix()->coverage()->lines() as $line) {
            $group = array_key_exists($line->file()->value(), $groups) ? $groups[$line->file()->value()] : [];
            $stay += self::alone($line, $names, []);
            $stay += $group === [] ? [] : self::alone($line, $names, $group);
        }

        return new self($stay);
    }

    public function has(TestName|TestId $test): bool
    {
        return array_key_exists($test->value(), $this->tests);
    }

    /**
     * The whole test that alone covers this line, among a group's tests where one is given.
     *
     * @param  array<string, true> $group the group's tests, by id; every test where empty
     * @return array<string, true>
     */
    private static function alone(CoveredLine $line, TestNames $names, array $group): array
    {
        $wholes = [];

        foreach ($line as $id) {
            $wholes += $group === [] || array_key_exists($id, $group)
                ? [$names->testOf(TestId::of($id))->value() => true]
                : [];
        }

        return count($wholes) === 1 ? $wholes : [];
    }

    /**
     * The tests of each held unit's group, by the unit's path: the tests that judge its mutants.
     *
     * @return array<string, array<string, true>>
     */
    private static function holdingGroups(Verdict $verdict): array
    {
        $held = self::heldPaths($verdict);

        foreach ($verdict->trees()->mutants() as $judged) {
            $path = $judged->mutant()->location()->file()->value();

            foreach (array_key_exists($path, $held) ? $judged->tests() : [] as $test) {
                $held[$path][$test->value()] = true;
            }
        }

        return $held;
    }

    /**
     * An empty group for each held unit, by its path.
     *
     * @return array<string, array<string, true>>
     */
    private static function heldPaths(Verdict $verdict): array
    {
        $held = [];

        foreach ($verdict->trees()->units() as $unit) {
            $held += $unit->unit()->isHeld() ? [$unit->unit()->path()->value() => []] : [];
        }

        return $held;
    }
}

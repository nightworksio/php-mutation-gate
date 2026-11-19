<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_key_exists;
use function array_map;
use function implode;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * What one control can vouch for among a file's kills (ADR-0033, ADR-0089,
 * ADR-0143).
 *
 * @internal
 */
final readonly class KillOrders
{
    /**
     * What vouches for each kill at once: every test that killed one of its
     * file's mutants, where killers run alone; the longest order of its file
     * that holds its own at its start, where its own is an order; and
     * otherwise its own, which only its own run can vouch for.
     *
     * @param  array<string, array{Path, TestIds|RunTests}> $tests by the mutant's gate id
     * @return array<string, array{Path, TestIds|RunTests}>
     */
    public static function vouchingFor(array $tests, bool $alone): array
    {
        $orders = self::ordersByFile($tests);
        $unions = array_map(TestIds::union(...), $orders);
        $vouching = [];

        foreach ($tests as $key => [$file, $own]) {
            $named = $file->value();
            $vouching[$key] = [
                $file,
                $alone ? $unions[$named] : self::longestFrom($own, $orders[$named]),
            ];
        }

        return $vouching;
    }

    /**
     * The distinct orders of each file, in the order the kills first name them.
     *
     * @param  array<string, array{Path, TestIds|RunTests}> $tests
     * @return array<string, array<string, TestIds>>
     */
    private static function ordersByFile(array $tests): array
    {
        $orders = [];

        foreach ($tests as [$file, $own]) {
            $spelt = $own instanceof TestIds ? implode("\n", array_map(self::named(...), [...$own])) : '';
            $known = array_key_exists($file->value(), $orders) ? $orders[$file->value()] : [];
            $orders[$file->value()] = $own instanceof TestIds ? $known + [$spelt => $own] : $known;
        }

        return $orders;
    }

    private static function named(TestId $test): string
    {
        return $test->value();
    }

    /**
     * The longest of these orders that starts with this one; this one where
     * it is no order but a run's selection.
     *
     * @param array<string, TestIds> $others
     */
    private static function longestFrom(TestIds|RunTests $own, array $others): TestIds|RunTests
    {
        $longest = $own;

        foreach ($others as $other) {
            $longest = $longest instanceof TestIds
                && $other->startsWith($longest) ? $other : $longest;
        }

        return $longest;
    }
}

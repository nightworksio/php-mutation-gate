<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function array_unique;
use function array_values;

use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Core\NotGiven;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Plugins\Mutate;

/**
 * The mutants of a suite a split stream judges (ADR-0117): each one's site,
 * the source file of each schema, the tests of the shard's part that cover
 * each, and what each one's own run is told.
 *
 * @internal
 */
final readonly class PlannedMutants
{
    /**
     * Each mutant a stream of the shard's part can judge: its site, by Pest's
     * id, the source file of each schema, by its key, the tests of the part
     * that cover each, and each mutant.
     *
     * @param  array<string, array<int, array<int, string>>> $coveredLines
     * @return array{
     *     array<string, array{string, int, string, array<string, string>}>,
     *     array<string, string>,
     *     array<string, list<string>>,
     *     array<string, MutationTest>,
     * }
     */
    public static function of(
        MutationSuite $suite,
        array $coveredLines,
        KillerHints $hints,
        TestParts $parts,
        bool $forks,
    ): array {
        $sites = [];
        $paths = [];
        $covering = [];
        $byId = [];

        foreach (self::testsOf($suite) as $test) {
            $switch = $forks ? Schemata::switchOf($test->mutation) : NotGiven::value();
            $covered = self::coveringOf($test, $coveredLines);
            $held = self::heldOf($test, $covered, $parts, $switch instanceof NotGiven);

            if ($switch instanceof NotGiven || $held === []) {
                continue;
            }

            $mutant = $test->mutation->id;
            $paths[$switch[0]] = (string) $test->mutation->file->getRealPath();
            $sites[$mutant] = [...$switch, $hints->killerOf($mutant, $held), self::variablesOf($test)];
            $covering[$mutant] = $held;
            $byId[$mutant] = $test;
        }

        return [$sites, $paths, $covering, $byId];
    }

    /** @return array<string, string> what a mutant's own run is told, which a child's subprocesses inherit */
    public static function variablesOf(MutationTest $test): array
    {
        return [
            Mutate::ENV_MUTATION_TESTING => (string) $test->mutation->file->getRealPath(),
            Mutate::ENV_MUTATION_FILE => $test->mutation->modifiedSourcePath,
            ...Schemata::variablesOf($test->mutation),
        ];
    }

    /** @return list<MutationTest> every mutant of the suite, in the order Pest holds them */
    private static function testsOf(MutationSuite $suite): array
    {
        $tests = [];

        foreach ($suite->repository->all() as $collection) {
            $tests = [...$tests, ...$collection->tests()];
        }

        return $tests;
    }

    /**
     * The covering tests of the shard's part a stream judges a mutant by;
     * none where no stream does: one no test covers, which Pest leaves
     * uncovered; one no stream can judge, which runs on its own in the shard
     * that owns it and is left by every other; and one no test of the part
     * covers, which is left to the parts that hold its tests.
     *
     * @param  list<string> $covered every test that covers it
     * @return list<string>
     */
    private static function heldOf(MutationTest $test, array $covered, TestParts $parts, bool $unstreamed): array
    {
        $held = $unstreamed ? [] : $parts->within($covered);
        $leaves = $unstreamed ? ! $parts->owns($covered) : $held === [];

        if ($covered !== [] && $leaves) {
            Parted::unreached($test);
        }

        if ($held !== []) {
            Parted::judgedBy($test, $parts->shareOf($covered), $held);
        }

        return $held;
    }

    /**
     * The tests that cover a mutant, as Pest gathers them for its filter.
     *
     * @param  array<string, array<int, array<int, string>>> $coveredLines
     * @return list<string>
     */
    private static function coveringOf(MutationTest $test, array $coveredLines): array
    {
        $covering = [];
        $file = (string) $test->mutation->file->getRealPath();

        for ($line = $test->mutation->startLine; $line <= $test->mutation->endLine; $line++) {
            $tests = array_key_exists($file, $coveredLines) && array_key_exists($line, $coveredLines[$file])
                ? $coveredLines[$file][$line]
                : [];
            $covering = [...$covering, ...$tests];
        }

        return array_values(array_unique($covering));
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;

use NightWorksIO\MutationGate\Adapter\Pest\Schemata;
use NightWorksIO\MutationGate\Core\NotGiven;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\MutationTestCollection;
use Pest\Mutate\Plugins\Mutate;

use function strval;

/**
 * The mutants of a suite a split stream judges (ADR-0117): each one's site,
 * the source file of each schema, the covering tests the shard judges each
 * by, and what each one's own run is told.
 *
 * @internal
 */
final readonly class PlannedMutants
{
    /**
     * Each mutant a stream of the shard can judge: its site, by Pest's id,
     * the test a first pass forks it at among them, the source file of each
     * schema, by its key, the covering tests the shard judges each by, each
     * mutant, those of its covering tests the shard's own part holds, which
     * a first pass streams, and each mutant first forked at its cheapest
     * covering test, not at the ledger's likely killer (ADR-0187).
     *
     * @param  array<string, array<int, array<int, string>>> $coveredLines
     * @return array{
     *     array<string, array{string, int, string, array<string, string>}>,
     *     array<string, string>,
     *     array<string, list<string>>,
     *     array<string, MutationTest>,
     *     array<string, list<string>>,
     *     array<string, true>,
     * }
     */
    public static function of(
        MutationSuite $suite,
        array $coveredLines,
        KillerHints $hints,
        FirstForks $first,
        TestParts $parts,
        bool $forks,
    ): array {
        $sites = [];
        $paths = [];
        $covering = [];
        $byId = [];
        $held = [];
        $killers = [];

        foreach (self::testsOf($suite) as $test) {
            $switch = $forks ? Schemata::switchOf($test->mutation) : NotGiven::value();
            $covered = self::coveringOf($test, $coveredLines);
            $mutant = $test->mutation->id;
            $among = $parts->killerAmong($covered);
            $killer = $among === [] ? '' : $hints->killerOf($mutant, $among);
            $judged = self::judgedOf($test, $covered, $killer, $parts, $switch instanceof NotGiven);

            if ($switch instanceof NotGiven || $judged === []) {
                continue;
            }

            $paths[$switch[0]] = (string) $test->mutation->file->getRealPath();
            $held[$mutant] = $parts->within($judged);
            $fork = $first->at($killer, $held[$mutant]);
            $sites[$mutant] = [...$switch, $fork, self::variablesOf($test)];
            $covering[$mutant] = $judged;
            $byId[$mutant] = $test;
            $killers[$mutant] = $killer;
        }

        return [$sites, $paths, $covering, $byId, $held, self::cheapestOf($sites, $killers)];
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

    /**
     * Each mutant whose first fork is not the ledger's likely killer of it,
     * which is its cheapest covering test (ADR-0187).
     *
     * @param  array<string, array{string, int, string, array<string, string>}> $sites
     * @param  array<string, string>                                             $killers each mutant's likely killer
     * @return array<string, true>
     */
    private static function cheapestOf(array $sites, array $killers): array
    {
        $cheapest = [];

        foreach ($sites as $mutant => $site) {
            if ($site[2] !== $killers[$mutant]) {
                $cheapest[$mutant] = true;
            }
        }

        return $cheapest;
    }

    /** @return list<MutationTest> every mutant of the suite, in the order Pest holds them */
    private static function testsOf(MutationSuite $suite): array
    {
        return array_merge(...array_map(
            static fn(MutationTestCollection $collection): array => array_values($collection->tests()),
            array_values($suite->repository->all()),
        ));
    }

    /**
     * The covering tests a stream of the shard judges a mutant by, whose
     * likely killer this is; none where no stream does: one no test covers,
     * which Pest leaves uncovered in the shard that owns it, the one its
     * file's lines no test covers fall to, and every other leaves (ADR-0171,
     * ADR-0193); one no stream can judge, which runs on its own in
     * the shard that owns it and is left by every other; and one the shard
     * judges by none of its covering tests, which is left to the shard that
     * does (ADR-0174).
     *
     * @param  list<string> $covered every test that covers it
     * @return list<string>
     */
    private static function judgedOf(
        MutationTest $test,
        array $covered,
        string $killer,
        TestParts $parts,
        bool $unstreamed,
    ): array {
        $mutant = $test->mutation->id;
        $judged = $unstreamed ? [] : $parts->judgedBy($covered, $mutant, $killer);
        $leaves = match (true) {
            $covered === [] => ! $parts->ownsUncovered($mutant, (string) $test->mutation->file->getRealPath()),
            $unstreamed => ! $parts->owns($covered, $mutant, $killer),
            default => $judged === [],
        };

        if ($leaves) {
            Parted::unreached($test);
        }

        if ($judged !== []) {
            Parted::judgedBy($test, $parts->shareOf($covered, $mutant, $killer), $judged);
        }

        return $judged;
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
        $lines = array_key_exists($file, $coveredLines) ? $coveredLines[$file] : [];

        for ($line = $test->mutation->startLine; $line <= $test->mutation->endLine; $line++) {
            foreach (array_key_exists($line, $lines) ? $lines[$line] : [] as $covered) {
                $covering[$covered] = true;
            }
        }

        return array_map(strval(...), array_keys($covering));
    }
}

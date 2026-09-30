<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use function array_key_exists;

use Generator;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

use function sprintf;

/**
 * The kill matrix as CSV, for a spreadsheet or a notebook: a header, then
 * one record per mutant and covering test, produced one at a time so a
 * large matrix is written as it is read. `status` is the gate's judgement,
 * `source` whether the mutant's unit was run, proved or carried, and
 * `outcome` what is known of the test with the mutant in place (ADR-0014,
 * decisions 9 and 10).
 */
final readonly class KillMatrixCsv
{
    /** @return Generator<int, string> each record, ending in CRLF */
    public static function records(Verdict $verdict): Generator
    {
        $origins = [];

        foreach ($verdict->trees()->units() as $unit) {
            $origins[$unit->unit()->path()->value()] = $unit->origin();
        }

        yield Csv::record('mutant', 'file', 'line', 'mutator', 'status', 'source', 'test', 'outcome', 'matrix');

        foreach ($verdict->trees()->mutants() as $judged) {
            $file = $judged->mutant()->location()->file()->value();
            $origin = array_key_exists($file, $origins) ? $origins[$file] : Origin::Run;

            foreach ($verdict->matrix()->coveredBy($judged) as $test) {
                yield self::record($verdict, $judged, $origin, $test);
            }
        }
    }

    private static function record(Verdict $verdict, JudgedMutant $judged, Origin $origin, TestId $test): string
    {
        $mutant = $judged->mutant();
        $matrix = $verdict->matrix();

        return Csv::record(
            $mutant->id()->value(),
            $mutant->location()->file()->value(),
            sprintf('%d', $mutant->location()->start()->number()),
            $mutant->mutation()->mutator(),
            $judged->judgement()->value,
            $origin->value,
            $matrix->names()->nameOf($test)->value(),
            $matrix->outcome($judged, $test)->value,
            $matrix->kind()->value,
        );
    }
}
